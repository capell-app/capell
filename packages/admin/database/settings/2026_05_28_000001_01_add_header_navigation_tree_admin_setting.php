<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('admin.enable_header_navigation_tree')) {
            $this->migrator->add('admin.enable_header_navigation_tree', true);
        }
    }
};
