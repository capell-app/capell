<?php

declare(strict_types=1);

use Capell\Admin\Actions\NormalizeDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\DashboardToolsPage;
use Capell\Admin\Filament\Settings\Schemas\DashboardSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Dashboard\DashboardFilamentWidgetRegistry;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\HiddenCompositionWidget;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\SettingsGatedWidgetAlpha;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\SettingsGatedWidgetBeta;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    app()->instance(DashboardFilamentWidgetRegistry::class, new DashboardFilamentWidgetRegistry);
    app()->forgetInstance(CapellAdminManager::class);
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);
});

it('renders a widget registered without a region in the additional section', function (): void {
    CapellAdmin::registerDashboardFilamentWidget(SettingsGatedWidgetAlpha::class, DashboardEnum::Main);

    Livewire::test(CapellDashboard::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::dashboard.region_additional'))
        ->assertSee(__('capell-admin::dashboard.additional_description'))
        ->assertSeeLivewire(SettingsGatedWidgetAlpha::class)
        ->assertDontSee(__('capell-admin::dashboard.empty_heading'));
});

it('retains explicit panel regions without duplicating them in additional', function (DashboardRegionEnum $region): void {
    CapellAdmin::registerDashboardPanel($region, SettingsGatedWidgetAlpha::class, DashboardEnum::Main);
    CapellAdmin::registerDashboardFilamentWidget(SettingsGatedWidgetAlpha::class, DashboardEnum::Main);

    $page = Livewire::test(CapellDashboard::class)
        ->assertSuccessful()
        ->assertSeeLivewire(SettingsGatedWidgetAlpha::class)
        ->assertDontSee(__('capell-admin::dashboard.region_additional'))
        ->assertDontSee(__('capell-admin::dashboard.empty_heading'));

    expect(substr_count((string) $page->html(), 'wire:name="capell.admin.tests.feature.dashboard.fixtures.settings-gated-widget-alpha"'))->toBe(1);
})->with([
    DashboardRegionEnum::Pulse,
    DashboardRegionEnum::Trends,
    DashboardRegionEnum::Insights,
    DashboardRegionEnum::Activity,
]);

it('places additional widgets after activity', function (): void {
    CapellAdmin::registerDashboardPanel(DashboardRegionEnum::Activity, SettingsGatedWidgetBeta::class, DashboardEnum::Main);
    CapellAdmin::registerDashboardFilamentWidget(SettingsGatedWidgetAlpha::class, DashboardEnum::Main);

    Livewire::test(CapellDashboard::class)
        ->assertSeeLivewire(SettingsGatedWidgetBeta::class)
        ->assertSeeLivewire(SettingsGatedWidgetAlpha::class)
        ->assertSeeInOrder([
            'wire:name="capell.admin.tests.feature.dashboard.fixtures.settings-gated-widget-beta"',
            __('capell-admin::dashboard.region_additional'),
            'wire:name="capell.admin.tests.feature.dashboard.fixtures.settings-gated-widget-alpha"',
        ], escape: false);
});

it('honours enabled settings in the overview and tools view', function (bool $enabled): void {
    CapellAdmin::registerDashboardFilamentWidget(SettingsGatedWidgetAlpha::class, DashboardEnum::Main);
    expect(array_column(DashboardSettingsSchema::allContributedKeys(DashboardEnum::Main), 'key'))->toContain('widget_alpha');
    $normalised = NormalizeDashboardFilamentWidgetSettingsAction::run([
        'widget_layout' => [['key' => 'widget_alpha', 'enabled' => $enabled, 'order' => 5]],
    ], DashboardEnum::Main);
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = $normalised['enabled_widgets'];
    $settings->widget_order = $normalised['widget_order'];
    $settings->save();

    $page = Livewire::test(CapellDashboard::class)->assertSuccessful();

    if ($enabled) {
        $page->assertSeeLivewire(SettingsGatedWidgetAlpha::class)
            ->assertDontSee(__('capell-admin::dashboard.empty_heading'));
        expect(DashboardToolsPage::canAccess())->toBeTrue();
        Livewire::test(DashboardToolsPage::class)->assertSeeLivewire(SettingsGatedWidgetAlpha::class);
    } else {
        $page->assertDontSee('wire:name="capell.admin.tests.feature.dashboard.fixtures.settings-gated-widget-alpha"', escape: false, stripInitialData: false)
            ->assertDontSee(__('capell-admin::dashboard.region_additional'))
            ->assertSee(__('capell-admin::dashboard.empty_heading'));
        expect(DashboardToolsPage::canAccess())->toBeFalse();
    }
})->with([true, false]);

it('omits additional widgets which cannot be viewed', function (): void {
    CapellAdmin::registerDashboardFilamentWidget(HiddenCompositionWidget::class, DashboardEnum::Main);

    Livewire::test(CapellDashboard::class)
        ->assertSuccessful()
        ->assertDontSee('wire:name="capell.admin.tests.feature.dashboard.fixtures.hidden-composition-widget"', escape: false, stripInitialData: false)
        ->assertDontSee(__('capell-admin::dashboard.region_additional'))
        ->assertSee(__('capell-admin::dashboard.empty_heading'));
    expect(DashboardToolsPage::canAccess())->toBeFalse();
});

it('retains the empty state when no widgets are registered', function (): void {
    Livewire::test(CapellDashboard::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::dashboard.empty_heading'))
        ->assertSee(__('capell-admin::dashboard.overview_empty_description'))
        ->assertDontSee(__('capell-admin::dashboard.region_additional'))
        ->assertDontSee('Stub');
});

it('keeps marketing studio contributions available in the specialist tools view', function (): void {
    CapellAdmin::registerDashboardFilamentWidget(SettingsGatedWidgetAlpha::class, DashboardEnum::MarketingStudio);

    Livewire::test(CapellDashboard::class)
        ->assertSuccessful()
        ->assertDontSee('wire:name="capell.admin.tests.feature.dashboard.fixtures.settings-gated-widget-alpha"', escape: false, stripInitialData: false)
        ->assertSee(__('capell-admin::dashboard.empty_heading'));
    Livewire::test(DashboardToolsPage::class)
        ->assertSuccessful()
        ->assertSeeLivewire(SettingsGatedWidgetAlpha::class);
});
