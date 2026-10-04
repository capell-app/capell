<?php

declare(strict_types=1);

use Composer\Semver\Semver;

require_once dirname(__DIR__, 2) . '/scripts/ComposerMajorConstraints.php';

it('admits locked stable majors in manifests and command overrides without vendor dependencies', function (): void {
    expect(ComposerMajorConstraints::failures(dirname(__DIR__, 2)))->toBe([]);
});

it('rejects an older major pin on a scratch copy and accepts an explicit union', function (string $path, string $section): void {
    $root = sys_get_temp_dir() . '/capell-composer-majors-' . bin2hex(random_bytes(8));
    mkdir($root . '/' . dirname($path), 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/composer.lock', json_encode([
        'packages' => [['name' => 'symfony/html-sanitizer', 'version' => 'v8.1.8']],
        'packages-dev' => [],
    ], JSON_THROW_ON_ERROR));

    $writeConstraint = function (string $constraint) use ($root, $path, $section): void {
        file_put_contents($root . '/' . $path, $section === 'command'
            ? 'composer require "symfony/html-sanitizer:' . $constraint . '"'
            : json_encode([$section => ['symfony/html-sanitizer' => $constraint]], JSON_THROW_ON_ERROR));
    };

    try {
        $writeConstraint('^7.0');
        expect(ComposerMajorConstraints::failures($root))->toBe([
            $path . ': symfony/html-sanitizer ^7.0 excludes locked stable major 8.',
        ]);

        $writeConstraint('^7.0 || ^8.0');
        expect(ComposerMajorConstraints::failures($root))->toBe([]);
    } finally {
        unlink($root . '/' . $path);

        if ($path !== 'composer.json') {
            unlink($root . '/composer.json');
        }

        unlink($root . '/composer.lock');
        $directory = dirname($root . '/' . $path);

        while ($directory !== $root) {
            rmdir($directory);
            $directory = dirname($directory);
        }

        rmdir($root);
    }
})->with([
    'aggregate runtime' => ['composer.json', 'require'],
    'aggregate development' => ['composer.json', 'require-dev'],
    'split runtime' => ['packages/core/composer.json', 'require'],
    'split development' => ['packages/marketplace/composer.json', 'require-dev'],
    'CI override' => ['.github/workflows/test.yml', 'command'],
    'provisioning override' => ['scripts/prepare.sh', 'command'],
]);

it('admits locked external releases in every published Composer manifest', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array<string, list<array{name: string, version: string, replace?: array<string, string>}>> $lock */
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $versions = [];

    foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
        $versions[$package['name']] = $package['version'];

        foreach ($package['replace'] ?? [] as $name => $constraint) {
            if ($constraint === 'self.version') {
                $versions[$name] = $package['version'];
            }
        }
    }

    $failures = [];

    foreach ([$root . '/composer.json', ...(glob($root . '/packages/*/composer.json') ?: [])] as $path) {
        /** @var array{require?: array<string, string>, 'require-dev'?: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach (['require', 'require-dev'] as $section) {
            foreach ($manifest[$section] ?? [] as $name => $constraint) {
                if (isset($versions[$name]) && ! Semver::satisfies($versions[$name], $constraint)) {
                    $failures[] = sprintf('%s: %s %s excludes locked %s.', $path, $name, $constraint, $versions[$name]);
                }
            }
        }
    }

    expect($failures)->toBe([], implode(PHP_EOL, $failures));
});

/**
 * @return array{0: int, 1: string}
 */
function packageDependencyRun(string ...$arguments): array
{
    $root = dirname(__DIR__, 2);

    $command = sprintf(
        '%s %s %s 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/scripts/check-package-dependencies.php'),
        implode(' ', array_map(escapeshellarg(...), $arguments)),
    );

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

it('passes for every package with the recorded baseline applied', function (): void {
    [$exitCode, $output] = packageDependencyRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Package dependency contract satisfied');
});

it('fails when the baseline is not applied, proving the analyser is wired', function (): void {
    [$exitCode, $output] = packageDependencyRun('--package=marketplace', '--no-baseline');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Package dependency contract failed for: marketplace');
});

it('rejects an unknown package', function (): void {
    [$exitCode, $output] = packageDependencyRun('--package=nope');

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Unknown package: nope');
});

it('records baseline debt only for packages that exist', function (): void {
    $root = dirname(__DIR__, 2);

    /** @var array<string, array<string, list<string>>> $baseline */
    $baseline = require $root . '/scripts/package-dependency-baseline.php';

    foreach (array_keys($baseline) as $package) {
        expect($root . '/packages/' . $package . '/composer.json')->toBeFile();
    }
});

it('prints a candidate baseline without changing the accepted debt file', function (): void {
    $root = dirname(__DIR__, 2);
    $baselinePath = $root . '/scripts/package-dependency-baseline.php';
    $before = (string) file_get_contents($baselinePath);

    [$exitCode, $output] = packageDependencyRun('--package=marketplace', '--print-baseline');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("'marketplace' =>")
        ->and(file_get_contents($baselinePath))->toBe($before);
});

it('rejects automatic baseline rewrites', function (): void {
    [$exitCode, $output] = packageDependencyRun('--update');

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Baseline files are never written by this gate');
});
