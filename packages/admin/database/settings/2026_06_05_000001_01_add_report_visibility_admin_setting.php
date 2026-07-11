<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('admin.enabled_reports_by_role')) {
            $this->migrator->add('admin.enabled_reports_by_role', []);
        }
    }
};
