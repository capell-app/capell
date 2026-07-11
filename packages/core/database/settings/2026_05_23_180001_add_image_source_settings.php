<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'core.allowed_image_sources' => 'all',
            'core.default_image_source' => 'media',
            'core.allowed_remote_image_domains' => ['images.unsplash.com'],
            'core.allow_relative_image_urls' => true,
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists($key)) {
                $this->migrator->add($key, $value);
            }
        }
    }
};
