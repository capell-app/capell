<?php

declare(strict_types=1);

use Capell\Admin\Data\ImportEntryData;
use Capell\Admin\Filament\Actions\ImportHeaderAction;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\ImportEntryRegistry;
use Filament\Actions\Action;

it('shows a migration assistant fallback when no import handler is registered', function (): void {
    app()->instance(ImportEntryRegistry::class, new ImportEntryRegistry);
    bindAdminSettingsForImportHeaderActionTest();

    $actionGroup = ImportHeaderAction::make(ListPages::class);
    $actions = $actionGroup->getFlatActions();

    expect($actionGroup->getLabel())->toBe(__('capell-admin::exchanger.import.action_label'))
        ->and($actionGroup->getIcon())->toBe('heroicon-o-arrow-up-tray')
        ->and($actionGroup->isVisible())->toBeTrue()
        ->and(array_keys($actions))->toBe(['migrationAssistantRequired'])
        ->and($actions['migrationAssistantRequired']->getLabel())->toBe(__('capell-admin::exchanger.import.migration_assistant_required'));
});

it('uses registered visible import entries instead of the fallback', function (): void {
    $registry = new ImportEntryRegistry;
    $registry->register(new ImportEntryData(
        key: 'pages',
        labelKey: 'capell-admin::exchanger.import_pages',
        descriptionKey: null,
        icon: 'heroicon-o-document-arrow-up',
        sort: 10,
        pageClasses: [ListPages::class],
        actionFactory: fn (): Action => Action::make('importPages'),
    ));

    app()->instance(ImportEntryRegistry::class, $registry);
    bindAdminSettingsForImportHeaderActionTest();

    $actions = ImportHeaderAction::make(ListPages::class)->getFlatActions();

    expect(array_keys($actions))->toBe(['importPages'])
        ->and($actions['importPages']->getLabel())->toBe(__('capell-admin::exchanger.import_pages'))
        ->and($actions['importPages']->getIcon())->toBe('heroicon-o-document-arrow-up');
});

it('hides when import export is disabled or registered entries are unauthorized', function (): void {
    app()->instance(ImportEntryRegistry::class, new ImportEntryRegistry);
    bindAdminSettingsForImportHeaderActionTest(enableImportExport: false);

    expect(ImportHeaderAction::make(ListPages::class)->isVisible())->toBeFalse();

    $registry = new ImportEntryRegistry;
    $registry->register(new ImportEntryData(
        key: 'sites',
        labelKey: 'capell-admin::exchanger.import_sites',
        descriptionKey: null,
        icon: 'heroicon-o-building-office',
        sort: 10,
        pageClasses: [ListSites::class],
        actionFactory: fn (): Action => Action::make('importSites'),
        authorize: fn (): bool => false,
    ));

    app()->instance(ImportEntryRegistry::class, $registry);
    bindAdminSettingsForImportHeaderActionTest();

    expect(ImportHeaderAction::make(ListSites::class)->isVisible())->toBeFalse();
});

function bindAdminSettingsForImportHeaderActionTest(bool $enableImportExport = true): void
{
    $settings = new AdminSettings;
    $settings->enable_import_export = $enableImportExport;

    app()->instance(AdminSettings::class, $settings);
}
