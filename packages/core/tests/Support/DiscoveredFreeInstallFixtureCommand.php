<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Console\Command;

final class DiscoveredFreeInstallFixtureCommand extends Command
{
    protected $signature = 'test:discovered-free-install';

    public function handle(): int
    {
        app()->instance('discovered-free-install-ran', true);

        return self::SUCCESS;
    }
}
