<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Illuminate\Console\Command;

class UpgradeCommand extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upgrade capell-frontend';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'capell:frontend-upgrade';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'capell-migrations']);

        $this->call('migrate');

        $this->call('vendor:publish', ['--tag' => 'capell-frontend-assets', '--force' => true]);

        $this->call('vendor:publish', ['--tag' => 'capell-frontend-publish', '--force' => true]);

        $this->newLine();
        $this->info('Capell Frontend upgraded successfully.');

        return Command::SUCCESS;
    }
}
