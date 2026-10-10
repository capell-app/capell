<?php

declare(strict_types=1);

use Capell\Core\Support\Migration\MigrationFileScanner;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

it('returns sorted unique migration names and optionally excludes stubs', function (): void {
    $root = sys_get_temp_dir() . '/capell-migration-scan-' . bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->ensureDirectoryExists($root);
    foreach (['2026_01_02_000000_second.php', '2026_01_01_000000_first.php', '2026_01_03_000000_third.php.stub', '2026_01_01_000000_first.php.stub', 'README.md'] as $name) {
        $files->put($root . '/' . $name, 'owned fixture');
    }

    try {
        expect(MigrationFileScanner::names($root))->toBe([
            '2026_01_01_000000_first', '2026_01_02_000000_second', '2026_01_03_000000_third',
        ])->and(MigrationFileScanner::names($root, includeStubs: false))->toBe([
            '2026_01_01_000000_first', '2026_01_02_000000_second',
        ]);
    } finally {
        $files->deleteDirectory($root);
    }
});

it('returns an empty list for an unavailable migration directory', function (): void {
    expect(MigrationFileScanner::names(sys_get_temp_dir() . '/missing-migrations-' . bin2hex(random_bytes(8))))->toBe([]);
});

it('returns an empty list when the filesystem cannot read migration files', function (): void {
    $original = File::getFacadeRoot();
    File::swap(new class
    {
        public function glob(string $pattern, int $flags = 0): false
        {
            return false;
        }
    });
    try {
        expect(MigrationFileScanner::names('/unreadable'))->toBe([]);
    } finally {
        File::swap($original);
    }
});
