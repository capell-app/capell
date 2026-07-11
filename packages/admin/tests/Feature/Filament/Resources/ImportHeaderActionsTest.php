<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Filament\Resources\Redirects\Pages\ManageRedirects;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Admin\Settings\AdminSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
    bindAdminSettingsForResourceImportHeaderActionTest();
});

it('prepends the shared import action before create and add actions on content resource pages', function (string $pageClass, string $method, string $nextAction): void {
    $actions = resourceHeaderActionsForImportHeaderActionTest($pageClass, $method);
    $importAction = importActionGroupForImportHeaderActionTest($actions);

    expect($importAction->getLabel())->toBe(__('capell-admin::exchanger.import.action_label'))
        ->and($importAction->isVisible())->toBeTrue()
        ->and(array_key_exists('migrationAssistantRequired', $importAction->getFlatActions()))->toBeTrue()
        ->and(resourceHeaderActionNamesForImportHeaderActionTest($actions)[1] ?? null)->toBe($nextAction);
})->with([
    'pages' => [ListPages::class, 'getActions', 'choosePageType'],
    'sites' => [ListSites::class, 'getActions', 'create'],
    'media' => [ListMedia::class, 'getHeaderActions', 'upload-files'],
    'layouts' => [ListLayouts::class, 'getActions', 'create'],
    'blueprints' => [ManageBlueprints::class, 'getActions', 'create'],
    'themes' => [ManageThemes::class, 'getActions', 'installMarketplaceTheme'],
    'page urls' => [ManagePageUrls::class, 'getActions', 'create'],
    'languages' => [ManageLanguages::class, 'getActions', 'create'],
]);

it('moves redirect csv import under the shared import group', function (): void {
    $actions = resourceHeaderActionsForImportHeaderActionTest(ManageRedirects::class, 'getActions');
    $importAction = importActionGroupForImportHeaderActionTest($actions);

    expect(array_key_exists('importRedirects', $importAction->getFlatActions()))->toBeTrue()
        ->and(resourceHeaderActionNamesForImportHeaderActionTest($actions)[1] ?? null)->toBe('create')
        ->and(resourceHeaderActionNamesForImportHeaderActionTest($actions)[2] ?? null)->toBe('export');
});

it('hides the shared import action when import export is disabled', function (): void {
    bindAdminSettingsForResourceImportHeaderActionTest(enableImportExport: false);

    $actions = resourceHeaderActionsForImportHeaderActionTest(ListPages::class, 'getActions');
    $importAction = importActionGroupForImportHeaderActionTest($actions);

    expect($importAction->isVisible())->toBeFalse();
});

/**
 * @return array<int, Action|ActionGroup>
 */
function resourceHeaderActionsForImportHeaderActionTest(string $pageClass, string $method): array
{
    $page = resolve($pageClass);

    /** @var array<int, Action|ActionGroup> $actions */
    $actions = (fn (): array => $this->{$method}())->call($page);

    return $actions;
}

/**
 * @param  array<int, Action|ActionGroup>  $actions
 * @return array<int, string>
 */
function resourceHeaderActionNamesForImportHeaderActionTest(array $actions): array
{
    return collect($actions)
        ->map(function (Action|ActionGroup $action): string {
            if ($action instanceof ActionGroup) {
                return 'grouped';
            }

            return (string) $action->getName();
        })
        ->values()
        ->all();
}

/**
 * @param  array<int, Action|ActionGroup>  $actions
 */
function importActionGroupForImportHeaderActionTest(array $actions): ActionGroup
{
    $importAction = $actions[0] ?? null;

    throw_unless($importAction instanceof ActionGroup, RuntimeException::class, 'Expected the first header action to be the import action group.');

    return $importAction;
}

function bindAdminSettingsForResourceImportHeaderActionTest(bool $enableImportExport = true): void
{
    $settings = new AdminSettings;
    $settings->enable_import_export = $enableImportExport;

    app()->instance(AdminSettings::class, $settings);
}
