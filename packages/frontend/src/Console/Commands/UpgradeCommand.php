<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Core\Actions\PublishMigrationsAction;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
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
        $migrationPublishExitCode = $this->call('vendor:publish', ['--tag' => 'capell-migrations']);

        if ($migrationPublishExitCode !== self::SUCCESS) {
            $this->error(__('capell-frontend::messages.frontend_migration_publish_failed'));

            return self::FAILURE;
        }

        $schemaMigrationExitCode = $this->call('migrate', ['--force' => true]);

        if ($schemaMigrationExitCode !== self::SUCCESS) {
            $this->error(__('capell-frontend::messages.frontend_schema_migrations_failed'));

            return self::FAILURE;
        }

        $settingsPath = __DIR__ . '/../../../database/settings';
        $publishResult = PublishMigrationsAction::run(
            type: 'settings',
            items: resolve(SettingsMigrationProviderInterface::class)->getSettingMigrations(),
            path: $settingsPath,
        );

        foreach ($publishResult->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($publishResult->errors as $error) {
            $this->error($error);
        }

        if (! $publishResult->successful()) {
            return self::FAILURE;
        }

        foreach ($publishResult->lines as $line) {
            $this->line($line);
        }

        $settingsMigrationExitCode = $this->call('migrate', [
            '--path' => 'database/settings',
            '--force' => true,
        ]);

        if ($settingsMigrationExitCode !== self::SUCCESS) {
            $this->error(__('capell-frontend::messages.frontend_settings_migrations_failed'));

            return self::FAILURE;
        }

        $this->call('vendor:publish', ['--tag' => 'capell-frontend-assets', '--force' => true]);

        $this->call('vendor:publish', ['--tag' => 'capell-frontend-publish', '--force' => true]);

        $this->newLine();
        $this->info('Capell Frontend upgraded successfully.');

        return Command::SUCCESS;
    }
}
