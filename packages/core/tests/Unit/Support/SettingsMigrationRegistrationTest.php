<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;

it('registers every core settings migration file for publishing', function (): void {
    $settingsDirectory = dirname(__DIR__, 3) . '/database/settings';
    $migrationFiles = glob($settingsDirectory . '/*.php') ?: [];

    $migrationNames = collect($migrationFiles)
        ->map(fn (string $path): string => basename($path, '.php'))
        ->sort()
        ->values()
        ->all();

    $registeredMigrations = collect(CapellCore::getSettingMigrations())
        ->sort()
        ->values()
        ->all();

    expect($registeredMigrations)->toBe($migrationNames);
});
