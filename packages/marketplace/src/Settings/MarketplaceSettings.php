<?php

declare(strict_types=1);

namespace Capell\Marketplace\Settings;

use Capell\Core\Contracts\SettingsContract;
use Capell\Marketplace\Filament\Settings\MarketplaceSettingsSchema;
use Spatie\LaravelSettings\Settings;

final class MarketplaceSettings extends Settings implements SettingsContract
{
    public ?string $owner_name = null;

    public ?string $owner_email = null;

    public ?string $owner_organisation = null;

    public bool $security_notifications_enabled = false;

    public bool $bug_notifications_enabled = false;

    public bool $marketing_notifications_enabled = false;

    public static function group(): string
    {
        return 'marketplace';
    }

    public static function schema(): string
    {
        return MarketplaceSettingsSchema::class;
    }
}
