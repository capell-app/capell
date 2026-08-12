<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('accepts a checkout that owns its vendor directory', function (): void {
    $root = vendorIntegrityFixture();

    try {
        [$exitCode, $output] = runVendorIntegrityCheck($root);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Vendor integrity is verified.');
    } finally {
        deleteVendorIntegrityFixture($root);
    }
});

it('accepts an owned checkout when Windows paths use native separators', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        test()->markTestSkipped('Windows path semantics are covered by the hosted Windows workflow.');
    }

    $root = vendorIntegrityFixture();

    try {
        [$exitCode, $output] = runVendorIntegrityCheck(str_replace('/', '\\', $root));

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Vendor integrity is verified.');
    } finally {
        deleteVendorIntegrityFixture($root);
    }
});

it('refuses when a mutated package directory is symlinked to another checkout', function (): void {
    $root = vendorIntegrityFixture();
    $primary = vendorIntegrityFixture();

    try {
        deleteVendorIntegrityPath($root . '/vendor/orchestra/testbench-core');
        symlink($primary . '/vendor/orchestra/testbench-core', $root . '/vendor/orchestra/testbench-core');

        [$exitCode, $output] = runVendorIntegrityCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('vendor/ is not owned by this checkout')
            ->and($output)->toContain('vendor/orchestra/testbench-core resolves to')
            ->and($output)->toContain('scripts/init-worktree.sh');
    } finally {
        deleteVendorIntegrityFixture($root);
        deleteVendorIntegrityFixture($primary);
    }
});

it('refuses when the whole vendor directory is symlinked', function (): void {
    $root = vendorIntegrityFixture();
    $primary = vendorIntegrityFixture();

    try {
        deleteVendorIntegrityPath($root . '/vendor');
        symlink($primary . '/vendor', $root . '/vendor');

        [$exitCode, $output] = runVendorIntegrityCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('vendor resolves to');
    } finally {
        deleteVendorIntegrityFixture($root);
        deleteVendorIntegrityFixture($primary);
    }
});

it('reports a broken symlink instead of treating it as owned', function (): void {
    $root = vendorIntegrityFixture();

    try {
        deleteVendorIntegrityPath($root . '/vendor/composer');
        symlink($root . '/vendor/does-not-exist', $root . '/vendor/composer');

        [$exitCode, $output] = runVendorIntegrityCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain('vendor/composer is a broken symlink.');
    } finally {
        deleteVendorIntegrityFixture($root);
    }
});

it('asks for composer install when vendor is missing', function (): void {
    $root = vendorIntegrityFixture();

    try {
        deleteVendorIntegrityPath($root . '/vendor');

        [$exitCode, $output] = runVendorIntegrityCheck($root);

        expect($exitCode)->toBe(2)
            ->and($output)->toContain("Run 'composer install'");
    } finally {
        deleteVendorIntegrityFixture($root);
    }
});

function vendorIntegrityFixture(): string
{
    $root = sys_get_temp_dir() . '/capell-vendor-integrity-' . bin2hex(random_bytes(8));

    foreach (['/vendor/composer', '/vendor/orchestra/testbench-core'] as $directory) {
        mkdir($root . $directory, 0777, true);
    }

    return $root;
}

/**
 * @return array{int, string}
 */
function runVendorIntegrityCheck(string $root): array
{
    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/check-vendor-integrity.php'],
        $root,
        ['CAPELL_VENDOR_INTEGRITY_ROOT' => $root],
    );
    $process->run();

    return [$process->getExitCode() ?? 1, $process->getOutput() . $process->getErrorOutput()];
}

function deleteVendorIntegrityFixture(string $path): void
{
    deleteVendorIntegrityPath($path);
}

function deleteVendorIntegrityPath(string $path): void
{
    if (is_link($path)) {
        unlink($path);

        return;
    }

    if (! file_exists($path)) {
        return;
    }

    if (! is_dir($path)) {
        unlink($path);

        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($path);
}
