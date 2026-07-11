<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Illuminate\Support\Facades\Route;

final class MarketplaceWebhookUrl
{
    public static function resolve(): ?string
    {
        if (Route::has('capell.marketplace.webhook')) {
            return route('capell.marketplace.webhook', absolute: true);
        }

        $configuredWebhookUrl = config('capell-marketplace.marketplace.webhook_url');

        if (is_string($configuredWebhookUrl) && $configuredWebhookUrl !== '') {
            return $configuredWebhookUrl;
        }

        return null;
    }

    public static function isAvailable(): bool
    {
        return self::resolve() !== null;
    }
}
