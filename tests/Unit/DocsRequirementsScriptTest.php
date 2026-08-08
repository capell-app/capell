<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('verifies the live documented requirements tables', function (): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([PHP_BINARY, 'scripts/check-docs-requirements.php'], $root);

    $process->mustRun();

    expect($process->getOutput())->toContain('requirements tables agree with composer.json.');
});

it('accepts an aligned fixture including the package readme tables', function (): void {
    $root = docsRequirementsFixture();

    try {
        [$exitCode, $output] = runDocsRequirementsCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('requirements tables agree with composer.json.');
    } finally {
        deleteDocsRequirementsFixture($root);
    }
});

it('rejects a documented version that no longer derives from the constraint', function (): void {
    $root = docsRequirementsFixture();

    try {
        replaceDocsRequirementsRow(
            $root . '/README.md',
            'Laravel',
            '12.41.1+ in the 12.x line or Laravel 13.x',
        );

        [$exitCode, $output] = runDocsRequirementsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('12.41.1')
            ->and($output)->toContain('does not derive from');
    } finally {
        deleteDocsRequirementsFixture($root);
    }
});

it('rejects a retired constraint alternative in a package readme table', function (): void {
    $root = docsRequirementsFixture();

    try {
        replaceDocsRequirementsRow(
            $root . '/packages/marketplace/README.md',
            'Laravel',
            '`^12.41.1` or `^13.0`',
        );

        [$exitCode, $output] = runDocsRequirementsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('packages/marketplace/README.md')
            ->and($output)->toContain('12.41.1')
            ->and($output)->toContain('does not derive from');
    } finally {
        deleteDocsRequirementsFixture($root);
    }
});

it('still rejects a documented row that omits the constraint version', function (): void {
    $root = docsRequirementsFixture();

    try {
        replaceDocsRequirementsRow($root . '/README.md', 'Laravel', '12.x');

        [$exitCode, $output] = runDocsRequirementsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('does not mention 13');
    } finally {
        deleteDocsRequirementsFixture($root);
    }
});

it('rejects a package readme without the expected requirements row', function (): void {
    $root = docsRequirementsFixture();

    try {
        $readmePath = $root . '/packages/admin/README.md';
        $readmeContents = file_get_contents($readmePath);
        $readmeContents = preg_replace('/^\|\s*Laravel\s*\|.*$\n/m', '', (string) $readmeContents);
        file_put_contents($readmePath, (string) $readmeContents);

        [$exitCode, $output] = runDocsRequirementsCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain("packages/admin/README.md: no requirements table row labelled 'Laravel'.");
    } finally {
        deleteDocsRequirementsFixture($root);
    }
});

function docsRequirementsFixture(): string
{
    $root = sys_get_temp_dir() . '/capell-docs-requirements-' . bin2hex(random_bytes(8));
    mkdir($root . '/docs/getting-started', 0777, true);

    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '^8.4',
            'laravel/framework' => '^13.0',
            'filament/filament' => '~5.6.8',
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    $rootTable = implode("\n", [
        '## Requirements',
        '',
        '| Tool     | Supported versions |',
        '| -------- | ------------------ |',
        '| PHP      | 8.4+               |',
        '| Laravel  | 13.x               |',
        '| Filament | 5.6.8+ (`~5.6.8`)  |',
        '',
    ]);

    file_put_contents($root . '/README.md', $rootTable);
    file_put_contents($root . '/docs/getting-started/quickstart.md', $rootTable);
    file_put_contents($root . '/docs/getting-started/install.md', $rootTable);

    $packageManifests = [
        'core' => ['php' => '^8.4', 'illuminate/support' => '^13.0', 'symfony/process' => '^7.2|^8.0', 'symfony/html-sanitizer' => '^7.0|^8.0'],
        'admin' => ['php' => '^8.4', 'laravel/framework' => '^13.0', 'filament/filament' => '~5.6.8'],
        'frontend' => ['php' => '^8.4', 'laravel/framework' => '^13.0', 'livewire/livewire' => '^3.0|^4.0'],
        'installer' => ['php' => '^8.4'],
        'marketplace' => ['php' => '^8.4', 'laravel/framework' => '^13.0'],
    ];

    $packageTables = [
        'core' => [
            '| PHP                        | `^8.4` with `ext-intl` |',
            '| Laravel                    | `^13.0`                |',
            '| Filament support           | `~5.6.8`               |',
            '| Symfony filesystem/process | `^7.2` or `^8.0`       |',
            '| Symfony HTML sanitizer     | `^7.0` or `^8.0`       |',
        ],
        'admin' => [
            '| PHP      | `^8.4`  |',
            '| Laravel  | `^13.0` |',
            '| Filament | `~5.6.8` |',
        ],
        'frontend' => [
            '| PHP      | `^8.4`           |',
            '| Laravel  | `^13.0`          |',
            '| Livewire | `^3.0` or `^4.0` |',
        ],
        'installer' => [
            '| PHP     | `^8.4`                        |',
            '| Laravel | Host Laravel `^13.0` via Core |',
        ],
        'marketplace' => [
            '| PHP     | `^8.4`  |',
            '| Laravel | `^13.0` |',
        ],
    ];

    foreach ($packageManifests as $packageName => $requirements) {
        mkdir($root . '/packages/' . $packageName, 0777, true);

        file_put_contents($root . '/packages/' . $packageName . '/composer.json', json_encode([
            'require' => $requirements,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        file_put_contents($root . '/packages/' . $packageName . '/README.md', implode("\n", [
            '## Requirements And Support Policy',
            '',
            '| Surface | Supported versions |',
            '| ------- | ------------------ |',
            ...$packageTables[$packageName],
            '',
        ]));
    }

    return $root;
}

function replaceDocsRequirementsRow(string $documentPath, string $rowLabel, string $replacementCell): void
{
    $documentContents = (string) file_get_contents($documentPath);
    $replacedContents = preg_replace(
        '/^(\|\s*' . preg_quote($rowLabel, '/') . '\s*\|).*\|\s*$/m',
        '$1 ' . str_replace('\\', '\\\\', $replacementCell) . ' |',
        $documentContents,
    );

    file_put_contents($documentPath, (string) $replacedContents);
}

/**
 * @return array{int, string}
 */
function runDocsRequirementsCheck(string $root): array
{
    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-docs-requirements.php'],
        $root,
        ['CAPELL_DOCS_REQUIREMENTS_ROOT' => $root],
    );
    $process->run();

    return [$process->getExitCode() ?? 1, $process->getOutput() . $process->getErrorOutput()];
}

function deleteDocsRequirementsFixture(string $path): void
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
