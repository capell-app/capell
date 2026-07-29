<?php

declare(strict_types=1);

use Capell\Frontend\Settings\FrontendSettingsMigrationProvider;

it('exposes migration provider with non-empty set', function (): void {
    $provider = new FrontendSettingsMigrationProvider;

    $settingsDirectory = dirname(__DIR__, 3) . '/database/settings';
    $migrationFiles = glob($settingsDirectory . '/*.php') ?: [];
    $migrationNames = collect($migrationFiles)
        ->map(fn (string $path): string => basename($path, '.php'))
        ->sort()
        ->values()
        ->all();

    expect($provider->migrations())
        ->toBe([
            '2026_05_10_190835_01_create_frontend_settings',
            '2026_07_29_000001_add_scheduled_publication_invalidation_checkpoint',
        ])
        ->and(collect($provider->getSettingMigrations())->sort()->values()->all())
        ->toBe($migrationNames);
});
