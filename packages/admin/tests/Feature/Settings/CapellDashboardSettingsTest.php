<?php

declare(strict_types=1);

use Capell\Admin\Actions\NormalizeDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Actions\SyncDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionStatsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\InstalledExtensionsFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioQuickActionsFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Feature\Filament\Settings\Schemas\Fixtures\FixtureDashboardContributor;

it('round-trips widget toggles', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = ['list_pages' => false, 'my_work_queue' => true];
    $settings->save();

    $fresh = AdminSettings::instance()->refresh();
    expect($fresh->isWidgetEnabled('list_pages'))->toBeFalse();
    expect($fresh->isWidgetEnabled('my_work_queue'))->toBeTrue();
});

it('defaults to enabled when a widget key is missing', function (): void {
    $settings = AdminSettings::instance();
    expect($settings->isWidgetEnabled('never_seen_widget'))->toBeTrue();
});

it('loads report visibility settings as a plain nested array', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_reports_by_role = [
        'editor' => [
            'core.public_render_safety' => false,
        ],
    ];
    $settings->save();

    $fresh = AdminSettings::instance()->refresh();

    expect($fresh->enabled_reports_by_role)
        ->toBe([
            'editor' => [
                'core.public_render_safety' => false,
            ],
        ])
        ->and($fresh->isReportEnabledForRole('editor', 'core.public_render_safety'))->toBeFalse();
});

it('exposes sort order lookup with a high fallback', function (): void {
    $settings = resolve(AdminSettings::class);
    $settings->widget_order = ['list_pages' => 5];

    expect($settings->sortOrderFor('list_pages'))->toBe(5);
    expect($settings->sortOrderFor('unknown'))->toBeGreaterThanOrEqual(999);
});

it('exposes numeric tuning defaults', function (): void {
    $settings = resolve(AdminSettings::class);
    expect($settings->my_work_queue_limit)->toBe(15);
    expect($settings->recently_published_limit)->toBe(10);
    expect($settings->cache_health_refresh_interval_seconds)->toBe(60);
    expect($settings->{'ai_orchestrator_spend_window_days'})->toBe(30);
    expect($settings->enable_header_navigation_tree)->toBeTrue();
});

it('publishes admin settings migrations', function (): void {
    $settings = AdminSettings::instance();

    expect($settings)
        ->toHaveProperties([
            'form_action_position',
            'ai_orchestrator_spend_window_days',
            'enable_header_navigation_tree',
            'show_configurator_path_hints',
            'enabled_reports_by_role',
        ])
        ->not->toHaveProperty('widget_spans')
        ->and(CapellAdmin::getSettingMigrations())
        ->toBe([
            '2026_05_10_190834_01_add_admin_settings',
            '2026_05_28_000001_01_add_header_navigation_tree_admin_setting',
            '2026_06_01_000001_01_add_configurator_path_hint_admin_setting',
            '2026_06_05_000001_01_add_report_visibility_admin_setting',
        ]);
});

it('adds missing built-in dashboard Filament widget keys as enabled and package widgets as available', function (): void {
    app()->tag([FixtureDashboardContributor::class], DashboardSettingsContributor::TAG);

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = ['list_pages' => false];
    $settings->save();

    $fresh = SyncDashboardFilamentWidgetSettingsAction::run();

    expect($fresh->enabled_widgets)
        ->toHaveKey('fixture_a', false)
        ->toHaveKey('site_stats_overview', false)
        ->toHaveKey('list_pages', false);
});

it('provides settings keys for every built-in dashboard Filament widget', function (): void {
    expect(ListPagesFilamentWidget::settingsKey())->toBe('list_pages');
    expect(MyWorkQueueFilamentWidget::settingsKey())->toBe('my_work_queue');
    expect(RecentlyPublishedFilamentWidget::settingsKey())->toBe('recently_published');
    expect(MarketingStudioQuickActionsFilamentWidget::settingsKey())->toBe('marketing_studio.quick_actions');
    expect(ExtensionStatsOverviewFilamentWidget::settingsKey())->toBe('extensions.stats');
    expect(InstalledExtensionsFilamentWidget::settingsKey())->toBe('extensions.installed');
});

it('normalizes extension dashboard layout without changing main dashboard Filament widget settings', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        'list_pages' => true,
        'extensions.installed' => true,
        'extensions.health' => true,
    ];
    $settings->widget_order = [
        'list_pages' => 20,
        'extensions.installed' => 130,
    ];
    $settings->save();

    $normalised = NormalizeDashboardFilamentWidgetSettingsAction::run([
        'widget_layout' => [
            [
                'key' => 'extensions.installed',
                'enabled' => true,
                'order' => 12,
            ],
        ],
    ], DashboardEnum::Extensions);

    expect($normalised['enabled_widgets'])
        ->toHaveKey('list_pages', true)
        ->toHaveKey('extensions.installed', true)
        ->toHaveKey('extensions.health', false)
        ->and($normalised['widget_order'])
        ->toHaveKey('list_pages', 20)
        ->toHaveKey('extensions.installed', 12);
});

it('normalizes dashboard layout submitted as serialized field state', function (): void {
    $normalised = NormalizeDashboardFilamentWidgetSettingsAction::run([
        'widget_layout' => json_encode([
            [
                'key' => 'list_pages',
                'enabled' => true,
                'order' => 15,
            ],
        ], JSON_THROW_ON_ERROR),
    ], DashboardEnum::Main);

    expect($normalised['enabled_widgets'])
        ->toHaveKey('list_pages', true)
        ->toHaveKey('my_work_queue', false)
        ->and($normalised['widget_order'])
        ->toHaveKey('list_pages', 15)
        ->and($normalised)
        ->not->toHaveKey('widget_layout');
});

it('preserves malformed serialized dashboard layout for recovery', function (): void {
    $settings = [
        'widget_layout' => '{malformed',
        'enabled_widgets' => ['list_pages' => true],
    ];

    expect(NormalizeDashboardFilamentWidgetSettingsAction::run($settings, DashboardEnum::Main))
        ->toBe($settings);
});

it('repairs a fully disabled default widget state during install setup sync', function (): void {
    app()->tag([FixtureDashboardContributor::class], DashboardSettingsContributor::TAG);

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        'fixture_a' => false,
        'site_stats_overview' => false,
        'list_pages' => false,
        'recent_activity' => false,
    ];
    $settings->save();

    $fresh = SyncDashboardFilamentWidgetSettingsAction::run(repairFullyDisabledDefaults: true);

    expect($fresh->enabled_widgets)
        ->toHaveKey('fixture_a', false)
        ->toHaveKey('site_stats_overview', false)
        ->toHaveKey('list_pages', true)
        ->toHaveKey('recent_activity', true);
});

it('force enables defaults for install and setup commands', function (): void {
    app()->tag([FixtureDashboardContributor::class], DashboardSettingsContributor::TAG);

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        'fixture_a' => false,
        'site_stats_overview' => false,
        'list_pages' => true,
    ];
    $settings->save();

    $fresh = SyncDashboardFilamentWidgetSettingsAction::run(forceEnableDefaults: true);

    expect($fresh->enabled_widgets)
        ->toHaveKey('fixture_a', false)
        ->toHaveKey('site_stats_overview', false)
        ->toHaveKey('list_pages', true)
        ->toHaveKey('recent_activity', true);
});

it('repairs extension stats when it was synced as optional before becoming a default widget', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        'extensions.stats' => false,
        'extensions.installed' => true,
    ];
    $settings->save();

    $fresh = SyncDashboardFilamentWidgetSettingsAction::run();

    expect($fresh->enabled_widgets)
        ->toHaveKey('extensions.stats', true)
        ->toHaveKey('extensions.installed', true);
});

it('keeps customised account and filament info widget order during sync', function (): void {
    $settings = AdminSettings::instance();
    $settings->widget_order = [
        'account' => 12,
        'filament_info' => 13,
    ];
    $settings->save();

    $fresh = SyncDashboardFilamentWidgetSettingsAction::run();

    expect($fresh->widget_order)
        ->toHaveKey('account', 12)
        ->toHaveKey('filament_info', 13);
});

it('repairs over-enabled optional package widgets when loading dashboard settings', function (): void {
    app()->tag([FixtureDashboardContributor::class], DashboardSettingsContributor::TAG);

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        'fixture_a' => true,
        'site_stats_overview' => true,
        'list_pages' => true,
    ];
    $settings->save();

    $fresh = SyncDashboardFilamentWidgetSettingsAction::run(repairOverEnabledDefaults: true);

    expect($fresh->enabled_widgets)
        ->toHaveKey('fixture_a', false)
        ->toHaveKey('site_stats_overview', false)
        ->toHaveKey('list_pages', true);
});
