<?php

declare(strict_types=1);
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('core.default_locale')) {
            $this->migrator->add('core.default_locale', config('app.locale', 'en'));
        }
    }
};
