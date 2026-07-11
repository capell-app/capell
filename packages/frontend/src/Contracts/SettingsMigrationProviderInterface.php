<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

interface SettingsMigrationProviderInterface
{
    /**
     * @return array<int, string> List of settings migration base names without extension
     */
    public function getSettingMigrations(): array;
}
