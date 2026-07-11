<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Marketplace\MarketplaceAssetUrl;
use RuntimeException;

final class MarketplaceWebUrl
{
    public static function resolve(): string
    {
        $webUrl = MarketplaceAssetUrl::webUrl();

        throw_if($webUrl === null, RuntimeException::class, 'The marketplace web URL must be configured before opening Marketplace.');

        return $webUrl;
    }
}
