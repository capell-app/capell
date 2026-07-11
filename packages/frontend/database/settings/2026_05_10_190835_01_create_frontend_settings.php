<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('frontend.cache_enabled')) {
            $this->migrator->add('frontend.cache_enabled', true);
        }

        if (! $this->migrator->exists('frontend.cache_ttl')) {
            $this->migrator->add('frontend.cache_ttl', 3600);
        }

        if (! $this->migrator->exists('frontend.minify_html')) {
            $this->migrator->add('frontend.minify_html', true);
        }

        if (! $this->migrator->exists('frontend.enable_static_generation')) {
            $this->migrator->add('frontend.enable_static_generation', true);
        }

        if (! $this->migrator->exists('frontend.generate_sitemap')) {
            $this->migrator->add('frontend.generate_sitemap', true);
        }

        if (! $this->migrator->exists('frontend.custom_error_page_enabled')) {
            $this->migrator->add('frontend.custom_error_page_enabled', true);
        }

        if (! $this->migrator->exists('frontend.custom_maintenance_page_enabled')) {
            $this->migrator->add('frontend.custom_maintenance_page_enabled', true);
        }

        if (! $this->migrator->exists('frontend.meta_schema')) {
            $this->migrator->add('frontend.meta_schema', [
                'capell::schema.website',
                'capell::schema.webpage',
                'capell::schema.breadcrumb',
                'capell::schema.image',
                'capell::schema.organization',
                'capell::schema.graph',
            ]);
        }
    }
};
