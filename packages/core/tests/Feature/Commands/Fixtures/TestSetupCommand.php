<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Commands\Fixtures;

use Illuminate\Console\Command;

class TestSetupCommand extends Command
{
    public function __construct(string $signature = 'test:setup')
    {
        $this->signature = $signature;

        parent::__construct();
    }

    public function handle(): int
    {
        return Command::SUCCESS;
    }
}
