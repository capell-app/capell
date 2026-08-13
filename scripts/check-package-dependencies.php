<?php

declare(strict_types=1);

/**
 * Enforce the per-package Composer dependency contract.
 *
 * Each package under packages/ ships its own composer.json, and that file is
 * the only dependency manifest a third-party consumer installs. The aggregate
 * root manifest hides drift: a package can use a class it never required and
 * still resolve locally, because a sibling package pulled the vendor in. This
 * gate runs shipmonk/composer-dependency-analyser once per package so shadow,
 * unused, and misplaced dependencies surface against the shipped manifest.
 *
 * Packages have no vendor/ of their own, so each run works from a generated
 * manifest under .cache/dependency-analyser/<package>/ that points at the
 * repository vendor directory and registers every sibling PSR-4 root. Sibling
 * Capell classes therefore resolve as first-party code; cross-package ownership
 * stays the concern of the architecture tests.
 *
 * Known debt lives in scripts/package-dependency-baseline.php so the gate lands
 * green and fails only on new drift. The baseline is expected to shrink.
 *
 * Usage:
 *   php scripts/check-package-dependencies.php [--package=core] [--no-baseline] [--update]
 */
$root = dirname(__DIR__);
$binary = $root . '/vendor/bin/composer-dependency-analyser';
$workspace = $root . '/.cache/dependency-analyser';

if (! is_file($binary)) {
    fwrite(STDERR, "shipmonk/composer-dependency-analyser is not installed. Run: composer install\n");

    exit(2);
}

/** @var list<string> $arguments */
$arguments = array_slice($argv, 1);
$update = in_array('--update', $arguments, true);
$useBaseline = ! in_array('--no-baseline', $arguments, true) && ! $update;
$requestedPackage = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--package=')) {
        $requestedPackage = substr($argument, strlen('--package='));
    }
}

/** @var array<string, array{prod?: list<string>, dev?: list<string>, unknownClasses?: list<string>}> $baseline */
$baseline = $useBaseline ? require $root . '/scripts/package-dependency-baseline.php' : [];

/**
 * Resolve every package manifest in the aggregate.
 *
 * @return array<string, array<string, mixed>>
 */
function packageManifests(string $root): array
{
    $manifests = [];

    foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifestPath) {
        $package = basename(dirname($manifestPath));
        $decoded = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            continue;
        }

        $manifests[$package] = $decoded;
    }

    ksort($manifests);

    return $manifests;
}

/**
 * Map every PSR-4 namespace in the aggregate to an existing absolute directory.
 *
 * Manifests may declare roots that only exist once a package ships factories,
 * and a missing directory aborts the analyser instead of reporting a finding.
 *
 * @param  array<string, array<string, mixed>>  $manifests
 * @return array<string, string>
 */
function psr4Roots(string $root, array $manifests): array
{
    $roots = [];

    foreach ($manifests as $package => $manifest) {
        /** @var array<string, string> $declared */
        $declared = $manifest['autoload']['psr-4'] ?? [];

        foreach ($declared as $namespace => $relativePath) {
            $absolutePath = $root . '/packages/' . $package . '/' . trim($relativePath, '/');

            if (is_dir($absolutePath)) {
                $roots[$namespace] = $absolutePath;
            }
        }
    }

    return $roots;
}

/**
 * Write the generated manifest and analyser configuration for one package.
 *
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $psr4Roots
 * @param  list<string>  $siblingPackages
 * @param  array{prod?: list<string>, dev?: list<string>, unknownClasses?: list<string>}  $packageBaseline
 * @return array{manifest: string, config: string}
 */
function writeWorkspace(
    string $root,
    string $workspace,
    string $package,
    array $manifest,
    array $psr4Roots,
    array $siblingPackages,
    array $packageBaseline,
): array {
    $directory = $workspace . '/' . $package;

    if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
        fwrite(STDERR, sprintf("Unable to create analyser workspace at %s\n", $directory));

        exit(2);
    }

    $manifestPath = $directory . '/composer.json';
    file_put_contents($manifestPath, json_encode([
        'name' => $manifest['name'] ?? ('capell-app/' . $package),
        'require' => $manifest['require'] ?? new stdClass,
        'require-dev' => $manifest['require-dev'] ?? new stdClass,
        'autoload' => ['psr-4' => $psr4Roots],
        'config' => ['vendor-dir' => $root . '/vendor'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    $packageDirectory = $root . '/packages/' . $package;

    /** @var list<string> $prodPaths */
    $prodPaths = array_values(array_filter([
        $packageDirectory . '/src',
        $packageDirectory . '/database',
        $packageDirectory . '/config',
        $packageDirectory . '/routes',
    ], is_dir(...)));

    // Package tests are not scanned: they run from the aggregate root, whose
    // require-dev owns the test toolchain. A shipped packages/<package>/
    // composer.json makes no promise about them.
    /** @var list<string> $devPaths */
    $devPaths = [];

    $configPath = $directory . '/config.php';
    file_put_contents($configPath, generatedConfig($prodPaths, $devPaths, $siblingPackages, $packageBaseline));

    return ['manifest' => $manifestPath, 'config' => $configPath];
}

/**
 * Render the analyser configuration for one package.
 *
 * @param  list<string>  $prodPaths
 * @param  list<string>  $devPaths
 * @param  list<string>  $siblingPackages
 * @param  array{prod?: list<string>, dev?: list<string>, unknownClasses?: list<string>}  $packageBaseline
 */
function generatedConfig(array $prodPaths, array $devPaths, array $siblingPackages, array $packageBaseline): string
{
    $lines = [
        '<?php',
        '',
        'declare(strict_types=1);',
        '',
        '// Generated by scripts/check-package-dependencies.php. Do not edit.',
        '',
        'use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;',
        'use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;',
        '',
        'return (new Configuration())',
        '    ->disableComposerAutoloadPathScan()',
        // ext-* requirements are governed by the documented runtime
        // requirements gate, not by class usage in a single package.
        '    ->disableExtensionsAnalysis()',
        '    ->disableReportingUnmatchedIgnores()',
        // Sibling packages are declared requirements that resolve through the
        // registered PSR-4 roots, so their usage is invisible to the analyser.
        sprintf(
            '    ->ignoreErrorsOnPackages(%s, [ErrorType::UNUSED_DEPENDENCY])',
            var_export($siblingPackages, true),
        ),
    ];

    foreach ($prodPaths as $path) {
        $lines[] = sprintf('    ->addPathToScan(%s, isDev: false)', var_export($path, true));
    }

    foreach ($devPaths as $path) {
        $lines[] = sprintf('    ->addPathToScan(%s, isDev: true)', var_export($path, true));
    }

    foreach ($packageBaseline['prod'] ?? [] as $dependency) {
        $lines[] = sprintf(
            '    ->ignoreErrorsOnPackage(%s, [ErrorType::SHADOW_DEPENDENCY, ErrorType::DEV_DEPENDENCY_IN_PROD, ErrorType::UNUSED_DEPENDENCY, ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV])',
            var_export($dependency, true),
        );
    }

    foreach ($packageBaseline['unknownClasses'] ?? [] as $classRegex) {
        $lines[] = sprintf('    ->ignoreUnknownClassesRegex(%s)', var_export($classRegex, true));
    }

    $lines[] = ';';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Collect the current findings for one package from the analyser's JUnit
 * report. Parsing structured output keeps the baseline independent of the
 * console renderer's wording.
 *
 * @return array{prod: list<string>, unknownClasses: list<string>}
 */
function recordFindings(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command . ' --format=junit 2>/dev/null', $output, $exitCode);

    $document = new DOMDocument;

    if (! @$document->loadXML(implode("\n", $output))) {
        return ['prod' => [], 'unknownClasses' => []];
    }

    $dependencies = [];
    $unknownClasses = [];

    foreach ($document->getElementsByTagName('testsuite') as $suite) {
        $suiteName = $suite->getAttribute('name');

        foreach ($suite->getElementsByTagName('testcase') as $testCase) {
            $name = $testCase->getAttribute('name');

            if (str_contains($suiteName, 'unknown class')) {
                $unknownClasses[] = '~^' . preg_quote($name, '~') . '$~';

                continue;
            }

            if (str_starts_with($name, 'ext-')) {
                continue;
            }

            $dependencies[] = $name;
        }
    }

    $dependencies = array_values(array_unique($dependencies));
    $unknownClasses = array_values(array_unique($unknownClasses));
    sort($dependencies);
    sort($unknownClasses);

    return ['prod' => $dependencies, 'unknownClasses' => $unknownClasses];
}

/**
 * Render the baseline file.
 *
 * @param  array<string, array{prod: list<string>, unknownClasses: list<string>}>  $recorded
 */
function renderBaseline(array $recorded): string
{
    $entries = '';

    foreach ($recorded as $package => $findings) {
        if ($findings['prod'] === [] && $findings['unknownClasses'] === []) {
            continue;
        }

        $entries .= sprintf("    %s => [\n", var_export($package, true));

        foreach (['prod', 'unknownClasses'] as $section) {
            if ($findings[$section] === []) {
                continue;
            }

            $entries .= sprintf("        %s => [\n", var_export($section, true));

            foreach ($findings[$section] as $value) {
                $entries .= sprintf("            %s,\n", var_export($value, true));
            }

            $entries .= "        ],\n";
        }

        $entries .= "    ],\n";
    }

    return <<<PHP
    <?php

    declare(strict_types=1);

    /**
     * Accepted debt for scripts/check-package-dependencies.php.
     *
     * Each entry silences one Composer package for one Capell package. Entries are
     * expected to shrink: the fix is to declare the dependency in the owning
     * packages/<package>/composer.json, or to stop using the vendor there.
     *
     * Regenerate with: php scripts/check-package-dependencies.php --update
     *
     * Keys are Capell package directory names. Shape per entry:
     *   'prod' => Composer package names to ignore entirely
     *   'unknownClasses' => PCRE patterns for classes the analyser cannot autoload
     */
    return [
    {$entries}];

    PHP;
}

$manifests = packageManifests($root);

if ($requestedPackage !== null) {
    if (! isset($manifests[$requestedPackage])) {
        fwrite(STDERR, sprintf(
            "Unknown package: %s\nAvailable packages: %s\n",
            $requestedPackage,
            implode(', ', array_keys($manifests)),
        ));

        exit(2);
    }

    $manifests = [$requestedPackage => $manifests[$requestedPackage]];
}

$allManifests = packageManifests($root);
$psr4Roots = psr4Roots($root, $allManifests);

/** @var list<string> $siblingPackages */
$siblingPackages = array_values(array_filter(array_map(
    static fn (array $manifest): ?string => is_string($manifest['name'] ?? null) ? $manifest['name'] : null,
    $allManifests,
)));

$failed = [];
$recorded = [];

foreach ($manifests as $package => $manifest) {
    if (! $update) {
        printf("\n== %s ==\n", $package);
    }

    $paths = writeWorkspace(
        $root,
        $workspace,
        $package,
        $manifest,
        $psr4Roots,
        $siblingPackages,
        $baseline[$package] ?? [],
    );

    $command = sprintf(
        '%s %s --composer-json=%s --config=%s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($binary),
        escapeshellarg($paths['manifest']),
        escapeshellarg($paths['config']),
    );

    if ($update) {
        $recorded[$package] = recordFindings($command);

        printf("Recorded %d ignored dependencies for %s.\n", count($recorded[$package]['prod']), $package);

        continue;
    }

    $exitCode = 0;
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        $failed[] = $package;
    }
}

if ($update) {
    file_put_contents($root . '/scripts/package-dependency-baseline.php', renderBaseline($recorded));

    echo "\nBaseline written to scripts/package-dependency-baseline.php.\n";

    exit(0);
}

if ($failed !== []) {
    fwrite(STDERR, sprintf(
        "\nPackage dependency contract failed for: %s\n"
        . "Declare the missing packages in the owning packages/<package>/composer.json, or record accepted debt in scripts/package-dependency-baseline.php.\n",
        implode(', ', $failed),
    ));

    exit(1);
}

echo "\nPackage dependency contract satisfied.\n";

exit(0);
