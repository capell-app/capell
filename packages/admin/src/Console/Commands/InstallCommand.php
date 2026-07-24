<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\AdminPanelIntegration\IntegrateCapellAdminPanelAction;
use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Actions\SyncDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelChangeResultData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupOptionsData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupResultData;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:admin-install
        {--admin-panel-changes=manual : How to handle Filament admin panel changes: auto or manual}
        {--panel= : Panel provider filename, class name, panel ID, or absolute path for auto admin panel changes}';

    public function __construct(private readonly MigrationFilesystemInterface $filesystem)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        resolve(AdminRuntimeActivator::class)->activate();

        $this->writeCommandIntro('install Capell Admin', $this->adminInstallIntroDetails());

        Filament::getDefaultPanel()
            ->resources(array_map(fn (ResourceEnum $resourceEnum) => $resourceEnum->value, ResourceEnum::cases()));

        $settings = __DIR__ . '/../../../database/settings';
        if (! $this->filesystem->isDir($settings)) {
            $this->error('Settings directory does not exist.');

            return Command::FAILURE;
        }

        $this->call('capell:publish-migrations', [
            '--type' => 'settings',
            '--items' => CapellAdmin::getSettingMigrations(),
            '--path' => $settings,
        ]);

        $this->call('migrate', [
            '--path' => 'database/settings',
            '--force' => true,
        ]);

        $this->info('Assigning permissions to roles...');

        SyncCapellPermissionsAction::run(PermissionSyncMode::Install);

        SyncDashboardFilamentWidgetSettingsAction::run(forceEnableDefaults: true);

        $this->call('filament:clear-cached-components');

        $this->call('filament:cache-components');

        $this->callSilent('filament:assets');

        if (! $this->handleAdminPanelChanges()) {
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Capell Admin installed successfully.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function adminInstallIntroDetails(): array
    {
        $details = [];

        if ($this->option('admin-panel-changes') === 'auto') {
            $details[] = 'automatic Filament panel changes';
        }

        if ($this->option('panel')) {
            $details[] = 'a selected Filament panel';
        }

        return $details;
    }

    private function handleAdminPanelChanges(): bool
    {
        $mode = $this->option('admin-panel-changes');

        if (! is_string($mode) || ! in_array($mode, ['auto', 'manual'], true)) {
            $this->error('The --admin-panel-changes option must be either auto or manual.');

            return false;
        }

        if ($mode === 'manual') {
            $this->info('Admin panel changes left for manual setup.');

            return true;
        }

        $result = IntegrateCapellAdminPanelAction::run(new AdminPanelSetupOptionsData(
            panelPath: $this->panelOption(),
            discoverConfigurators: [['in' => 'Filament/Configurators', 'for' => 'App\\Filament\\Configurators']],
        ));

        $this->renderPanelSetupResult($result);

        return ! $result->hasFailures();
    }

    private function panelOption(): ?string
    {
        $panel = $this->option('panel');

        return is_string($panel) && $panel !== '' ? $panel : null;
    }

    private function renderPanelSetupResult(AdminPanelSetupResultData $result): void
    {
        if ($result->panelPath !== null) {
            $this->line('Filament panel: ' . str($result->panelPath)->after(base_path() . DIRECTORY_SEPARATOR));
        }

        if ($result->backupPath !== null) {
            $this->line('Backup: ' . $result->backupPath);
        }

        $this->table(
            ['Change', 'Status', 'Message'],
            collect($result->changes)
                ->map(fn (AdminPanelChangeResultData $change): array => [
                    $change->change,
                    $change->status->value,
                    $change->message,
                ])
                ->all(),
        );
    }
}
