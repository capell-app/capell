<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\MarketplaceSmokeQa;

use Capell\Tests\Fixtures\MarketplaceSmokeQa\Console\MarketplaceSmokeQaProbeCommand;
use Illuminate\Support\ServiceProvider;
use Override;

final class MarketplaceSmokeQaServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('capell.marketplace-smoke-qa.provider', true);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MarketplaceSmokeQaProbeCommand::class]);
        }
    }
}
