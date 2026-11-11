<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('first_run.published_sites')) {
            $this->migrator->add('first_run.published_sites', []);
        }
    }
};
