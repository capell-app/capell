<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Install;

use Illuminate\Console\Command;

final class MidInstallBoostTestCommand extends Command
{
    protected $signature = 'test:boost-mid-install';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
