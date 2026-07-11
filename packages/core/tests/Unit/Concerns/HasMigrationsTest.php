<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;

it('registers every core migration file for publishing', function (): void {
    $migrationPaths = glob(dirname(__DIR__, 3) . '/database/migrations/*.php');

    if ($migrationPaths === false) {
        $migrationPaths = [];
    }

    $migrationFiles = collect($migrationPaths)
        ->map(fn (string $path): string => basename($path, '.php'))
        ->values()
        ->all();

    expect(CapellCore::getMigrations())->toEqualCanonicalizing($migrationFiles);
});

it('registers the preferred admin language user migration with core migrations', function (): void {
    expect(CapellCore::getMigrations())->toContain('2026_05_10_190832_22_add_preferred_admin_language_to_users_table');
});

it('registers marketplace trust migrations with core migrations', function (): void {
    expect(CapellCore::getMigrations())
        ->toContain('2026_05_10_190832_26_create_capell_marketplace_installs_table')
        ->toContain('2026_05_10_190832_27_create_capell_extension_health_alerts_table');
});
