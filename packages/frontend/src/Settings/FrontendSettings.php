<?php

declare(strict_types=1);

namespace Capell\Frontend\Settings;

use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Contracts\SettingsSchemaContract;
use Capell\Frontend\Filament\Settings\FrontendSettingsSchema;
use Spatie\LaravelSettings\Settings;

class FrontendSettings extends Settings implements SettingsContract, SettingsSchemaContract
{
    public bool $cache_enabled = true;

    public int $cache_ttl = 3600;

    public bool $minify_html = true;

    public bool $enable_static_generation = true;

    public bool $generate_sitemap = true;

    public bool $custom_error_page_enabled = true;

    public bool $custom_maintenance_page_enabled = true;

    /** @var array<int, string> */
    public array $meta_schema = [];

    public static function group(): string
    {
        return 'frontend';
    }

    public static function schema(): string
    {
        return FrontendSettingsSchema::class;
    }
}
