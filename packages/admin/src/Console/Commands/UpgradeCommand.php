<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class UpgradeCommand extends Command
{
    protected $description = 'Upgrade capell-admin';

    protected $signature = 'capell:admin-upgrade';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'capell-migrations']);

        RunDatabaseMigrationsAction::run();

        $this->info('Refreshing permissions...');

        // See Capell\Admin\Console\Commands\SetupCommand::setupAuthentication()
        // — never let shield scaffold policy stubs into app/Policies on upgrade.
        config()->set('filament-shield.policies.generate', false);

        $this->call('shield:generate', [
            '--all' => true,
            '--ignore-existing-policies' => true,
            '--exclude' => [],
            '--option' => 'permissions',
            '--panel' => Filament::getCurrentOrDefaultPanel()?->getId(),
        ]);

        SyncCapellPermissionsAction::run(PermissionSyncMode::Upgrade);

        $this->call('filament:clear-cached-components');
        $this->callSilent('filament:cache-components');
        $this->callSilent('filament:assets');

        $this->newLine();
        $this->info('Admin package upgraded successfully.');

        return Command::SUCCESS;
    }
}
