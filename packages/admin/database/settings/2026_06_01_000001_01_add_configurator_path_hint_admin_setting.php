<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('admin.show_configurator_path_hints')) {
            $this->migrator->add('admin.show_configurator_path_hints', false);
        }
    }
};
