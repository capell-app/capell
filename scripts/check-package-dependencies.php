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
 *   php scripts/check-package-dependencies.php [--package=core] [--no-baseline] [--print-baseline]
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
$printBaseline = in_array('--print-baseline', $arguments, true);
$useBaseline = ! in_array('--no-baseline', $arguments, true) && ! $printBaseline;
$requestedPackage = null;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--package=')) {
        $requestedPackage = substr($argument, strlen('--package='));

        continue;
    }

    if (! in_array($argument, ['--no-baseline', '--print-baseline'], true)) {
        fwrite(STDERR, sprintf(
            "Unknown option: %s\nBaseline files are never written by this gate; edit scripts/package-dependency-baseline.php explicitly.\n",
            $argument,
        ));

        exit(2);
    }
}

/** @var array<string, array<string, list<string>>> $baseline */
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
 * @param  array<string, list<string>>  $packageBaseline
 * @return array{manifest: string, config: string}
 */
function writeWorkspace(
    string $root,
    string $workspace,
    string $package,
    array $manifest,
    array $psr4Roots,
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
    file_put_contents($configPath, generatedConfig($prodPaths, $devPaths, $packageBaseline));

    return ['manifest' => $manifestPath, 'config' => $configPath];
}

/**
 * Render the analyser configuration for one package.
 *
 * @param  list<string>  $prodPaths
 * @param  list<string>  $devPaths
 * @param  array<string, list<string>>  $packageBaseline
 */
function generatedConfig(array $prodPaths, array $devPaths, array $packageBaseline): string
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
    ];

    foreach ($prodPaths as $path) {
        $lines[] = sprintf('    ->addPathToScan(%s, isDev: false)', var_export($path, true));
    }

    foreach ($devPaths as $path) {
        $lines[] = sprintf('    ->addPathToScan(%s, isDev: true)', var_export($path, true));
    }

    $errorTypes = [
        'shadow' => 'SHADOW_DEPENDENCY',
        'devInProd' => 'DEV_DEPENDENCY_IN_PROD',
        'prodOnlyInDev' => 'PROD_DEPENDENCY_ONLY_IN_DEV',
        'unused' => 'UNUSED_DEPENDENCY',
    ];

    foreach ($errorTypes as $section => $errorType) {
        foreach ($packageBaseline[$section] ?? [] as $dependency) {
            $lines[] = sprintf(
                '    ->ignoreErrorsOnPackage(%s, [ErrorType::%s])',
                var_export($dependency, true),
                $errorType,
            );
        }
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
 * @return array<string, list<string>>
 */
function recordFindings(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command . ' --format=junit 2>/dev/null', $output, $exitCode);

    $xml = implode("\n", $output);
    $xmlStart = strpos($xml, '<?xml');

    if ($xmlStart === false) {
        fwrite(STDERR, "Unable to parse Composer dependency analyser output.\n");

        exit(2);
    }

    $document = new DOMDocument;

    if (! @$document->loadXML(substr($xml, $xmlStart))) {
        fwrite(STDERR, "Unable to parse Composer dependency analyser JUnit output.\n");

        exit(2);
    }

    $findings = [
        'unknownClasses' => [],
        'unknownFunctions' => [],
        'shadow' => [],
        'devInProd' => [],
        'prodOnlyInDev' => [],
        'unused' => [],
    ];

    $suiteSections = [
        'unknown classes' => 'unknownClasses',
        'unknown functions' => 'unknownFunctions',
        'shadow dependencies' => 'shadow',
        'dev dependencies in production code' => 'devInProd',
        'prod dependencies used only in dev paths' => 'prodOnlyInDev',
        'unused dependencies' => 'unused',
    ];

    foreach ($document->getElementsByTagName('testsuite') as $suite) {
        $section = $suiteSections[$suite->getAttribute('name')] ?? null;

        if ($section === null) {
            continue;
        }

        foreach ($suite->getElementsByTagName('testcase') as $testCase) {
            $name = $testCase->getAttribute('name');

            if (str_starts_with($name, 'ext-')) {
                continue;
            }

            $findings[$section][] = in_array($section, ['unknownClasses', 'unknownFunctions'], true)
                ? '~^' . preg_quote($name, '~') . '$~'
                : $name;
        }
    }

    foreach ($findings as &$sectionFindings) {
        $sectionFindings = array_values(array_unique($sectionFindings));
        sort($sectionFindings);
    }

    unset($sectionFindings);

    return $findings;
}

/**
 * Render the baseline file.
 *
 * @param  array<string, array<string, list<string>>>  $recorded
 */
function renderBaseline(array $recorded): string
{
    $entries = '';

    foreach ($recorded as $package => $findings) {
        if (array_filter($findings, static fn (array $section): bool => $section !== []) === []) {
            continue;
        }

        $entries .= sprintf("    %s => [\n", var_export($package, true));

        foreach (['shadow', 'devInProd', 'prodOnlyInDev', 'unused', 'unknownClasses', 'unknownFunctions'] as $section) {
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
     * To review current debt, run:
     *   php scripts/check-package-dependencies.php --print-baseline
     * This command only prints a candidate. It never writes this file.
     *
     * Keys are Capell package directory names. Shape per entry:
     * Sections are analyser error types. A new violation must be added here
     * explicitly, and stale entries fail through unmatched-ignore reporting.
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

$failed = [];
$recorded = [];

foreach ($manifests as $package => $manifest) {
    if (! $printBaseline) {
        printf("\n== %s ==\n", $package);
    }

    $paths = writeWorkspace(
        $root,
        $workspace,
        $package,
        $manifest,
        $psr4Roots,
        $baseline[$package] ?? [],
    );

    $command = sprintf(
        '%s %s --composer-json=%s --config=%s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($binary),
        escapeshellarg($paths['manifest']),
        escapeshellarg($paths['config']),
    );

    if ($printBaseline) {
        $recorded[$package] = recordFindings($command);

        continue;
    }

    $exitCode = 0;
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        $failed[] = $package;
    }
}

if ($printBaseline) {
    echo renderBaseline($recorded);

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
