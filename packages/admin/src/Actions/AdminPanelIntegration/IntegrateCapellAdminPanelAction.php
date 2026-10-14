<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\AdminPanelIntegration;

use Capell\Admin\Data\AdminPanelIntegration\AdminPanelCandidateData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelChangeResultData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupOptionsData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupResultData;
use Capell\Admin\Enums\AdminPanelChangeStatus;
use Capell\Admin\Enums\AdminPanelFailureCategory;
use Capell\Admin\Support\AdminPanelIntegration\AdminPanelProviderEditor;
use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Install\PanelProviderTargetResolver;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class IntegrateCapellAdminPanelAction
{
    use AsFake;
    use AsObject;

    private const string DOCS_URL = 'https://capellcms.com/docs/admin-setup';

    public function handle(AdminPanelSetupOptionsData $options): AdminPanelSetupResultData
    {
        $panels = DiscoverFilamentPanelsAction::run();
        $path = resolve(PanelProviderTargetResolver::class)->resolve($options->panelPath, adoptDefault: true)[0] ?? null;
        $panel = $panels->first(fn (AdminPanelCandidateData $candidate): bool => $candidate->path === $path);

        if (! $panel instanceof AdminPanelCandidateData) {
            return new AdminPanelSetupResultData(null, null, [
                new AdminPanelChangeResultData(
                    'panel',
                    AdminPanelChangeStatus::Failed,
                    'No Filament panels found. Run php artisan make:filament-panel first.',
                    AdminPanelFailureCategory::MissingPanel,
                    'https://filamentphp.com/docs/5.x/panels/installation',
                ),
            ], self::DOCS_URL);
        }

        try {
            $editor = new AdminPanelProviderEditor($panel->path);
            $changes = [];

            if ($options->addColors) {
                $changes[] = $editor->addColors();
            }

            $changes[] = $editor->addPlugin($options->discoverConfigurators);
            $changes[] = $editor->addSitePermissionScopeMiddleware();
            $changes[] = $editor->addDashboardPage();

            if ($options->addWidgets) {
                $changes[] = $editor->addWidgets();
            }

            if ($options->addNavigation) {
                $changes[] = $editor->addNavigation();
            }

            $hasAppliedChanges = collect($changes)->contains(
                fn (AdminPanelChangeResultData $change): bool => $change->status === AdminPanelChangeStatus::Applied,
            );

            $backupPath = null;
            if (! $options->preview && $hasAppliedChanges) {
                $backupPath = $options->createBackup ? $editor->backup() : null;
                $editor->save();
            }

            if (! $options->preview) {
                resolve(InstallFilamentPanelAction::class)->configureThemeForProvider($panel->path, new NullProgressReporter);
            }

            return new AdminPanelSetupResultData($panel->path, $backupPath, $changes, self::DOCS_URL);
        } catch (Throwable $throwable) {
            return new AdminPanelSetupResultData($panel->path, null, [
                new AdminPanelChangeResultData(
                    'panel',
                    AdminPanelChangeStatus::Failed,
                    $throwable->getMessage(),
                    AdminPanelFailureCategory::ParseError,
                    self::DOCS_URL,
                ),
            ], self::DOCS_URL);
        }
    }
}
