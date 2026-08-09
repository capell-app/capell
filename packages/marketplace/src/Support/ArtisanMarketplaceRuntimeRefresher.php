<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Marketplace\Contracts\MarketplaceRuntimeRefresher;
use Illuminate\Support\Facades\Artisan;

final class ArtisanMarketplaceRuntimeRefresher implements MarketplaceRuntimeRefresher
{
    public const string COMMAND = 'capell:runtime-refresh';

    public function refresh(): bool
    {
        return Artisan::call(self::COMMAND) === 0;
    }
}
