<?php

declare(strict_types=1);

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
