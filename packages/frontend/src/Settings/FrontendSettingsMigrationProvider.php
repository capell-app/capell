<?php

declare(strict_types=1);

namespace Capell\Frontend\Settings;

use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;

class FrontendSettingsMigrationProvider implements SettingsMigrationProviderInterface
{
    public function getSettingMigrations(): array
    {
        return [
            '2026_05_10_190835_01_create_frontend_settings',
        ];
    }

    public function migrations(): array
    {
        return $this->getSettingMigrations();
    }
}
