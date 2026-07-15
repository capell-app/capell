<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('validates the README engineering standards against CI', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/check-readme-engineering-standards.php'], $root);

    $process->mustRun();

    expect($process->getOutput())->toContain('README engineering standards are verified.');
});

it('reports a badge claim that drifts from its source of truth', function (): void {
    $root = sys_get_temp_dir() . '/capell-readme-standards-' . bin2hex(random_bytes(8));

    try {
        readmeEngineeringStandardsFixture($root);
        file_put_contents($root . '/README.md', 'parameters%20typed-98.9%25 dependencies-audited test-full.yml?branch=main&style=flat-square&label=test%20matrix code-quality-and-styling.yml?branch=main&style=flat-square&label=quality%20gates img.shields.io/codecov/c/github/capell-app/capell');

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-readme-engineering-standards.php'],
            $root,
            ['CAPELL_README_ENGINEERING_STANDARDS_ROOT' => $root],
        );

        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('README declares PHPStan level 8 (README.md).');
    } finally {
        readmeEngineeringStandardsDeleteDirectory($root);
    }
});

function readmeEngineeringStandardsFixture(string $root): void
{
    mkdir($root . '/phpstan', 0777, true);
    mkdir($root . '/.github/workflows', 0777, true);

    file_put_contents($root . '/README.md', 'PHPStan-level%208 parameters%20typed-98.9%25 dependencies-audited test-full.yml?branch=main&style=flat-square&label=test%20matrix code-quality-and-styling.yml?branch=main&style=flat-square&label=quality%20gates img.shields.io/codecov/c/github/capell-app/capell');
    file_put_contents($root . '/phpstan/common.neon', "level: 8\nparam_type: 98.9\n");
    file_put_contents($root . '/.github/workflows/code-quality-and-styling.yml', "pull_request:\n    branches:\n      - main\n      - 1.x\ncomposer run check:readme-engineering-standards\ncomposer phpstan\ncomposer audit --locked\n");
    file_put_contents($root . '/.github/workflows/test-full.yml', "laravel: 12.*\nlaravel: 13.*\n");
    file_put_contents($root . '/.github/workflows/coverage-release.yml', '--coverage --min=90');
}

function readmeEngineeringStandardsDeleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}
