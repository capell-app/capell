<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Illuminate\Console\Command;

class TestUpgradeCommand extends Command
{
    protected $signature = 'test:upgrade {--no-clear-cache}';

    public function handle(): int
    {
        return Command::SUCCESS;
    }
}
