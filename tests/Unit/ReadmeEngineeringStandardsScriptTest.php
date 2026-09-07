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
        file_put_contents($root . '/README.md', 'parameters%20typed-99.2%25 dependencies-audited test-full.yml?branch=main&style=flat-square&label=test%20matrix code-quality-and-styling.yml?branch=main&style=flat-square&label=quality%20gates img.shields.io/codecov/c/github/capell-app/capell');

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

it('generates package and test metrics from their source evidence', function (): void {
    $root = sys_get_temp_dir() . '/capell-readme-metrics-' . bin2hex(random_bytes(8));
    $packageCatalogueTest = $root . '/FirstPartyPackageCatalogueTest.php';
    $testListOutput = $root . '/pest-list.txt';

    mkdir($root, 0777, true);
    file_put_contents($packageCatalogueTest, <<<'PHP'
<?php

it('loads the complete first party product catalogue', function (): void {
    expect($catalogue)->toHaveCount(114);
});
PHP);
    file_put_contents($testListOutput, "Available tests:\n - one\n - two\n - three\n");

    try {
        $process = new Process(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/scripts/check-readme-engineering-standards.php',
                '--update',
                '--package-catalogue-test=' . $packageCatalogueTest,
                '--test-list-output=' . $testListOutput,
            ],
            $root,
            ['CAPELL_README_ENGINEERING_STANDARDS_ROOT' => $root],
        );

        $process->run();

        expect($process->isSuccessful())
            ->toBeTrue($process->getErrorOutput() . $process->getOutput());

        $metrics = json_decode(
            (string) file_get_contents($root . '/docs/reference/readme-engineering-metrics.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($metrics)->toMatchArray([
            'schema_version' => 1,
            'generated_by' => 'scripts/check-readme-engineering-standards.php',
            'package_count' => 114,
            'test_count' => 3,
        ])
            ->and($process->getOutput())
            ->toContain('package_count=114, test_count=3');
    } finally {
        readmeEngineeringStandardsDeleteDirectory($root);
    }
});

function readmeEngineeringStandardsFixture(string $root): void
{
    mkdir($root . '/phpstan', 0777, true);
    mkdir($root . '/.github/workflows', 0777, true);
    mkdir($root . '/scripts/test-all', 0777, true);
    mkdir($root . '/docs/reference', 0777, true);

    file_put_contents($root . '/README.md', 'PHPStan-level%208 parameters%20typed-99.2%25 dependencies-audited test-full.yml?branch=main&style=flat-square&label=test%20matrix code-quality-and-styling.yml?branch=main&style=flat-square&label=quality%20gates img.shields.io/codecov/c/github/capell-app/capell img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Fcapell-app%2Fcapell%2Fmain%2Fdocs%2Freference%2Freadme-engineering-metrics.json&query=%24.package_count&label=first-party%20packages img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Fcapell-app%2Fcapell%2Fmain%2Fdocs%2Freference%2Freadme-engineering-metrics.json&query=%24.test_count&label=Core%20Pest%20tests check-readme-engineering-standards.php --update');
    file_put_contents($root . '/phpstan/common.neon', "level: 8\nparam_type: 99.2\n");
    file_put_contents($root . '/.github/workflows/code-quality-and-styling.yml', "pull_request:\n    branches:\n      - main\ncomposer run check:readme-engineering-standards\ncomposer phpstan\ncomposer audit --locked\n");
    file_put_contents($root . '/.github/workflows/test-full.yml', "laravel: 13.*\n");
    file_put_contents($root . '/scripts/test-all/TestAllMatrix.php', "'laravel' => '13.*'\n");
    file_put_contents($root . '/.github/workflows/coverage-release.yml', '--coverage --min=90');
    file_put_contents($root . '/docs/reference/readme-engineering-metrics.json', <<<'JSON'
{
    "schema_version": 1,
    "generated_by": "scripts/check-readme-engineering-standards.php",
    "package_count": 114,
    "package_count_source": "capell-app/tests/Feature/Marketplace/FirstPartyPackageCatalogueTest.php",
    "test_count": 7526,
    "test_count_source": "vendor/bin/pest --list-tests --configuration=phpunit.xml"
}
JSON);
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
