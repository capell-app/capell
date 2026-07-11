<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Data\Activity\ActivityResourceLinkData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Support\Activity\ActivityResourceLinkRegistry;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Tests\Fixtures\Activity\ActivityResourceLinkRecord;
use Capell\Admin\Tests\Fixtures\Activity\AlternateActivityResourceLinkRecordResource;
use Capell\Admin\Tests\Fixtures\Autoload\TestActivityChangeSetBuilderForRegistrar;
use Capell\Admin\Tests\Fixtures\Autoload\TestActivityRevertHandlerForRegistrar;
use Capell\Admin\Tests\Fixtures\Autoload\TestDashboardFilamentWidgetForRegistrar;
use Capell\Admin\Tests\Fixtures\Autoload\TestPanelExtenderForRegistrar;
use Capell\Admin\Tests\Fixtures\Autoload\TestSchemaExtenderForRegistrar;
use Capell\Admin\Tests\Fixtures\Autoload\TestSettingsForRegistrar;
use Capell\Admin\Tests\Fixtures\Autoload\TestSettingsSchemaForRegistrar;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Support\Icons\Heroicon;

beforeEach(function (): void {
    CapellAdmin::clearAdminSurfaceContributions();
    CapellAdmin::clearActivityResourceLinks();
    CapellAdmin::clearUserMenuItems();
    resolve(SettingsSchemaRegistry::class)->removeGroup('admin-bridge-registrar-test');
});

it('registers admin surface contributions through existing registries', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->page(SettingsPage::class);
    $registrar->resource(SettingsPage::class, 'AdminBridgeRegistrarTest', 'settings');
    $registrar->widget(SettingsPage::class);
    $registrar->configurator(SettingsPage::class, 'AdminBridgeRegistrarTest', 'settings');
    $registrar->schemaExtender(TestSchemaExtenderForRegistrar::class, 'admin-bridge-registrar-test');
    $registrar->panelExtender(TestPanelExtenderForRegistrar::class);

    $contributions = CapellAdmin::getAdminSurfaceContributions();

    expect($contributions[AdminSurfaceContributionType::Page->value])->toHaveKey(SettingsPage::class)
        ->and($contributions[AdminSurfaceContributionType::Resource->value])->toHaveKey('resource:AdminBridgeRegistrarTest:settings')
        ->and($contributions[AdminSurfaceContributionType::Widget->value])->toHaveKey(SettingsPage::class)
        ->and($contributions[AdminSurfaceContributionType::PanelExtender->value])->toHaveKey(TestPanelExtenderForRegistrar::class)
        ->and($contributions[AdminSurfaceContributionType::Configurator->value])->toHaveKey('configurator:AdminBridgeRegistrarTest:settings')
        ->and($contributions[AdminSurfaceContributionType::SchemaExtender->value])->toHaveKey(
            'schema_extender:admin-bridge-registrar-test:' . TestSchemaExtenderForRegistrar::class,
        )
        ->and(collect(app()->tagged('admin-bridge-registrar-test'))->first())->toBeInstanceOf(TestSchemaExtenderForRegistrar::class)
        ->and(collect(app()->tagged(AdminPanelExtender::TAG))->first())->toBeInstanceOf(TestPanelExtenderForRegistrar::class);
});

it('registers dashboard Filament widgets through the admin manager', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->filamentDashboardWidget(TestDashboardFilamentWidgetForRegistrar::class, DashboardEnum::SystemHealth);

    expect(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::SystemHealth))
        ->toContain(TestDashboardFilamentWidgetForRegistrar::class);
});

it('registers extension dashboard Filament widgets through the bridge convenience method', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->extensionDashboardFilamentWidget(TestDashboardFilamentWidgetForRegistrar::class);

    expect(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Extensions))
        ->toContain(TestDashboardFilamentWidgetForRegistrar::class);
});

it('registers a dashboard page through the admin manager', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->dashboardPage(CapellDashboard::class);

    expect(CapellAdmin::getDashboardPage())->toBe(CapellDashboard::class);
});

it('registers user menu item definitions through the admin manager', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->userMenuItem(
        key: 'capell-test.bridge',
        label: 'Bridge item',
        icon: Heroicon::OutlinedBell,
        url: '/admin/bridge',
        badge: 7,
        badgeColor: 'warning',
        sort: 20,
        group: 'capell-test',
    );

    $definitions = CapellAdmin::getUserMenuItemDefinitions();

    expect($definitions)->toHaveKey('capell-test.bridge')
        ->and($definitions['capell-test.bridge']->key)->toBe('capell-test.bridge')
        ->and($definitions['capell-test.bridge']->label)->toBe('Bridge item')
        ->and($definitions['capell-test.bridge']->icon)->toBe(Heroicon::OutlinedBell)
        ->and($definitions['capell-test.bridge']->url)->toBe('/admin/bridge')
        ->and($definitions['capell-test.bridge']->badge)->toBe(7)
        ->and($definitions['capell-test.bridge']->badgeColor)->toBe('warning')
        ->and($definitions['capell-test.bridge']->sort)->toBe(20)
        ->and($definitions['capell-test.bridge']->group)->toBe('capell-test');
});

it('registers welcome tour steps through the admin manager', function (): void {
    CapellAdmin::clearWelcomeTourSteps();

    $registrar = new AdminBridgeRegistrar;

    $registrar->welcomeTourStep(
        key: 'registrar-test.welcome',
        title: 'Registrar welcome',
        description: 'Registered from a package bridge',
        element: '.registrar-welcome',
    );

    $steps = CapellAdmin::getWelcomeTourSteps();

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->key)->toBe('registrar-test.welcome')
        ->and($steps[0]->element)->toBe('.registrar-welcome');
});

it('registers activity extension handlers through Laravel tags', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->activityChangeSetBuilder(TestActivityChangeSetBuilderForRegistrar::class);
    $registrar->activityRevertHandler(TestActivityRevertHandlerForRegistrar::class);

    // EventSourcedActivityRevertHandler is tagged at boot, so the registrar's
    // handler is not guaranteed to be first() in the tagged collection — assert
    // containment instead. (Resolution itself orders by priority(), not insertion.)
    expect(collect(app()->tagged(ActivityChangeSetBuilder::TAG))->first())->toBeInstanceOf(TestActivityChangeSetBuilderForRegistrar::class)
        ->and(collect(app()->tagged(ActivityRevertHandler::TAG))->contains(
            fn (object $handler): bool => $handler instanceof TestActivityRevertHandlerForRegistrar,
        ))->toBeTrue();
});

it('registers activity resource links through the admin manager', function (): void {
    $registrar = new AdminBridgeRegistrar;

    $registrar->activityResourceLink(
        subjectClass: ActivityResourceLinkRecord::class,
        resourceClass: AlternateActivityResourceLinkRecordResource::class,
    );

    $record = new ActivityResourceLinkRecord([
        'id' => 12,
        'name' => 'Bridge linked record',
    ]);
    $record->exists = true;

    $link = resolve(ActivityResourceLinkRegistry::class)->resolve($record);

    expect($link)->toBeInstanceOf(ActivityResourceLinkData::class);
    assert($link instanceof ActivityResourceLinkData);

    expect($link->resourceClass)->toBe(AlternateActivityResourceLinkRecordResource::class);
});

it('registers settings schemas through the settings schema registry', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);
    $registrar = new AdminBridgeRegistrar;

    $registrar->settingsSchema('admin-bridge-registrar-test', TestSettingsSchemaForRegistrar::class, 'bridge-test');

    expect($registry->getSchema('admin-bridge-registrar-test', 'bridge-test'))->toBe(TestSettingsSchemaForRegistrar::class);
});

it('registers settings classes and metadata through the settings schema registry', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);
    $registrar = new AdminBridgeRegistrar;
    $metadata = new SettingsGroupMetadata(
        group: 'admin-bridge-registrar-test',
        label: 'Admin bridge registrar test',
        navigationSort: 40,
        packageName: 'capell-app/admin-bridge-registrar-test',
    );

    $registrar->settingsClass('admin-bridge-registrar-test', TestSettingsForRegistrar::class);
    $registrar->settingsMetadata($metadata);

    expect($registry->getSettingsClass('admin-bridge-registrar-test'))->toBe(TestSettingsForRegistrar::class)
        ->and($registry->getMetadata('admin-bridge-registrar-test'))->toBe($metadata);
});
