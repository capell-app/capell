<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Console\Commands\Concerns\CallsRequiredCommands;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class UpgradeCommand extends Command
{
    use CallsRequiredCommands;

    protected $description = 'Upgrade capell-admin';

    protected $signature = 'capell:admin-upgrade';

    public function handle(): int
    {
        resolve(AdminRuntimeActivator::class)->activate();

        if (! $this->callRequired('vendor:publish', ['--tag' => 'capell-migrations'])) {
            return self::FAILURE;
        }

        $migrationResult = RunDatabaseMigrationsAction::run();

        if ($migrationResult->exitCode !== self::SUCCESS) {
            $this->error($migrationResult->output);
            $this->error(__('capell::message.required_command_failed', [
                'command' => 'database migrations',
                'exit_code' => $migrationResult->exitCode,
            ]));

            return self::FAILURE;
        }

        $this->info('Refreshing permissions...');

        // See Capell\Admin\Console\Commands\SetupCommand::setupAuthentication()
        // — never let shield scaffold policy stubs into app/Policies on upgrade.
        config()->set('filament-shield.policies.generate', false);

        if (! $this->callRequired('shield:generate', [
            '--all' => true,
            '--ignore-existing-policies' => true,
            '--exclude' => [],
            '--option' => 'permissions',
            '--panel' => Filament::getCurrentOrDefaultPanel()?->getId(),
        ])) {
            return self::FAILURE;
        }

        SyncCapellPermissionsAction::run(PermissionSyncMode::Upgrade);

        if (! $this->callRequired('filament:clear-cached-components')) {
            return self::FAILURE;
        }

        if (! $this->callRequired('filament:cache-components')) {
            return self::FAILURE;
        }

        if (! $this->callRequired('filament:assets')) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Admin package upgraded successfully.');

        return Command::SUCCESS;
    }
}
