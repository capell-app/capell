<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Providers;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

class InstallCommandProvider extends ServiceProvider
{
    public function boot(): void
    {
        Artisan::command('test:manifest-install-command', fn (): int => Command::SUCCESS);
    }
}
