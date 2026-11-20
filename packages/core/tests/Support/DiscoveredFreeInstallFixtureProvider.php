<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Support\ServiceProvider;

final class DiscoveredFreeInstallFixtureProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([DiscoveredFreeInstallFixtureCommand::class]);
    }
}
