<?php

declare(strict_types=1);

namespace Capell\Core\Settings;

use Capell\Admin\Filament\Settings\CoreSettingsSchema;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Contracts\SettingsSchemaContract;
use Spatie\LaravelSettings\Settings;

class CoreSettings extends Settings implements SettingsContract, SettingsSchemaContract
{
    public string $default_locale;

    public string $default_image_source = 'media';

    public string $allowed_image_sources = 'all';

    /** @var list<string> */
    public array $allowed_remote_image_domains = ['images.unsplash.com'];

    public bool $allow_relative_image_urls = true;

    public static function group(): string
    {
        return 'core';
    }

    public static function schema(): string
    {
        return CoreSettingsSchema::class;
    }
}
