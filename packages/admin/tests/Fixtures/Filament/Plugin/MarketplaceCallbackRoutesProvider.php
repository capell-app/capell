<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Illuminate\Support\ServiceProvider;

/** Loads the real Marketplace callback route file without booting the Marketplace package. */
final class MarketplaceCallbackRoutesProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(dirname(__DIR__, 5) . '/marketplace/routes/marketplace.php');
    }
}
