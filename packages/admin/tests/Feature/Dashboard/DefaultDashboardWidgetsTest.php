<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard;

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Widgets\Dashboard\CapellAccountFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\CapellInfoFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\PageStatusFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\SiteStatsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\UpdateAdvisoryFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionActionsFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionHealthFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionStatsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\InstalledExtensionsFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioAdvancedFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioLaunchReadinessFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioQuickActionsFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioTimelineFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioWorkQueueFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Models\Site;
use Capell\Marketplace\Filament\Widgets\MarketplacePackageOperationsAlertFilamentWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;

uses(CreatesAdminUser::class);

it('registers built-in dashboard Filament widgets by default', function (): void {
    expect(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main))
        ->toContain(CapellAccountFilamentWidget::class)
        ->toContain(CapellInfoFilamentWidget::class)
        ->not->toContain(MyWorkQueueFilamentWidget::class)
        ->not->toContain(RecentlyPublishedFilamentWidget::class)
        ->not->toContain(PageStatusFilamentWidget::class)
        ->not->toContain(SiteStatsOverviewFilamentWidget::class)
        ->not->toContain(UpdateAdvisoryFilamentWidget::class)
        ->toContain(RecentActivityFilamentWidget::class)
        ->toContain(ListPagesFilamentWidget::class);
});

it('registers built-in extensions dashboard Filament widgets by default', function (): void {
    expect(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Extensions))
        ->toContain(ExtensionStatsOverviewFilamentWidget::class)
        ->toContain(ExtensionHealthFilamentWidget::class)
        ->toContain(ExtensionActionsFilamentWidget::class)
        ->toContain(InstalledExtensionsFilamentWidget::class)
        ->not->toContain(ListPagesFilamentWidget::class);
});

it('registers built-in marketing studio widgets by default', function (): void {
    expect(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::MarketingStudio))
        ->toContain(MarketingStudioQuickActionsFilamentWidget::class)
        ->toContain(MarketingStudioWorkQueueFilamentWidget::class)
        ->toContain(MarketingStudioLaunchReadinessFilamentWidget::class)
        ->toContain(MarketingStudioTimelineFilamentWidget::class)
        ->toContain(MarketingStudioAdvancedFilamentWidget::class)
        ->not->toContain(ListPagesFilamentWidget::class);
});

it('orders dashboard Filament widgets by their filament sort value', function (): void {
    $widgets = CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main);

    expect($widgets)->toBe([
        CapellAccountFilamentWidget::class,
        CapellInfoFilamentWidget::class,
        ListPagesFilamentWidget::class,
        RecentActivityFilamentWidget::class,
        MarketplacePackageOperationsAlertFilamentWidget::class,
    ]);
});

it('keeps account and filament info widgets on the installed dashboard', function (): void {
    Site::factory()->createOne();

    $widgets = (new CapellDashboard)->getWidgets();

    expect($widgets)
        ->toContain(CapellInfoFilamentWidget::class)
        ->toContain(CapellAccountFilamentWidget::class)
        ->toContain(ListPagesFilamentWidget::class);
});

it('keeps auth and filament widgets above dashboard Filament widgets ordered by admin settings', function (): void {
    $settings = AdminSettings::instance();
    $settings->widget_order = [
        ListPagesFilamentWidget::settingsKey() => 1,
    ];
    $settings->save();

    $widgets = CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main);

    expect(array_slice($widgets, 0, 3))->toBe([
        CapellAccountFilamentWidget::class,
        CapellInfoFilamentWidget::class,
        ListPagesFilamentWidget::class,
    ]);
});

it('filters globally registered widgets through admin settings and callbacks', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = collect(FilamentWidgetEnum::cases())
        ->mapWithKeys(fn (FilamentWidgetEnum $widget): array => [$widget->value => false])
        ->merge([
            FilamentWidgetEnum::PageStatusFilamentWidget->value => true,
            FilamentWidgetEnum::RecentlyPublishedFilamentWidget->value => true,
        ])
        ->all();
    $settings->save();

    $enabledWidgets = CapellAdmin::getWidgets(true);
    $disabledWidgets = CapellAdmin::getWidgets(false);
    $filteredWidgets = CapellAdmin::getWidgets(
        fn (FilamentWidgetEnum $widget): bool => str_contains($widget->value, 'Dashboard\\'),
    );

    expect($enabledWidgets)->toContain(FilamentWidgetEnum::PageStatusFilamentWidget, FilamentWidgetEnum::RecentlyPublishedFilamentWidget)
        ->not->toContain(FilamentWidgetEnum::MyWorkQueueFilamentWidget)
        ->and($disabledWidgets)->toContain(FilamentWidgetEnum::MyWorkQueueFilamentWidget)
        ->not->toContain(FilamentWidgetEnum::PageStatusFilamentWidget)
        ->and($filteredWidgets)->toContain(FilamentWidgetEnum::ListPagesFilamentWidget, FilamentWidgetEnum::AccountFilamentWidget);

    CapellAdmin::setEnabledWidgets([
        FilamentWidgetEnum::PageStatusFilamentWidget,
        FilamentWidgetEnum::RecentlyPublishedFilamentWidget->value,
    ]);

    expect(AdminSettings::instance()->enabled_widgets)->toMatchArray([
        FilamentWidgetEnum::PageStatusFilamentWidget->value => true,
        FilamentWidgetEnum::RecentlyPublishedFilamentWidget->value => true,
    ]);
});

it('falls back to filament sort values after pinned dashboard Filament widgets without admin order', function (): void {
    $widgets = CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::Main);

    expect(array_slice($widgets, 0, 2))->toBe([
        CapellAccountFilamentWidget::class,
        CapellInfoFilamentWidget::class,
    ])
        ->and(array_values(array_diff($widgets, [
            CapellAccountFilamentWidget::class,
            CapellInfoFilamentWidget::class,
        ])))
        ->toBe([
            ListPagesFilamentWidget::class,
            RecentActivityFilamentWidget::class,
            MarketplacePackageOperationsAlertFilamentWidget::class,
        ]);
});

it('applies dashboard Filament widget enabled state without overriding native widget layout', function (): void {
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        ListPagesFilamentWidget::settingsKey() => false,
        MyWorkQueueFilamentWidget::settingsKey() => true,
    ];
    $settings->save();

    $method = new ReflectionMethod(CapellDashboard::class, 'configuredDashboardFilamentWidgets');
    $configuredWidgets = $method->invoke(new CapellDashboard, [
        ListPagesFilamentWidget::class,
        MyWorkQueueFilamentWidget::class,
    ]);

    expect($configuredWidgets)->toHaveCount(1)
        ->and($configuredWidgets[0])->toBe(MyWorkQueueFilamentWidget::class)
        ->and((new MyWorkQueueFilamentWidget)->getColumnSpan())->toBe([
            'default' => 'full',
            'lg' => 1,
        ]);

    assertOptionalDashboardFilamentWidgetsKeepNativeColumnSpan(
        [
            'Capell\\PublishingStudio\\Filament\\Widgets\\WorkspaceActivityFilamentWidget',
            'Capell\\Diagnostics\\Filament\\Widgets\\Health\\SiteHealthFilamentWidget',
        ],
        [
            'default' => 'full',
            'lg' => 1,
        ],
    );
});

it('uses a responsive dashboard layout for content-heavy widgets', function (): void {
    $dashboard = new CapellDashboard;
    $getColumns = new ReflectionMethod(CapellDashboard::class, 'getColumns');

    expect($getColumns->getDeclaringClass()->getName())->toBe(CapellDashboard::class)
        ->and($dashboard->getColumns())->toBe([
            'default' => 1,
            'lg' => 2,
        ])
        ->and((new ListPagesFilamentWidget)->getColumnSpan())->toBe([
            'default' => 'full',
        ])
        ->and((new RecentActivityFilamentWidget)->getColumnSpan())->toBe([
            'default' => 'full',
        ])
        ->and((new CapellAccountFilamentWidget)->getColumnSpan())->toBe([
            'default' => 'full',
            'lg' => 1,
        ])
        ->and((new MarketplacePackageOperationsAlertFilamentWidget)->getColumnSpan())->toBe([
            'default' => 'full',
        ])
        ->and((new UpdateAdvisoryFilamentWidget)->getColumnSpan())->toBe([
            'default' => 'full',
        ])
        ->and($dashboard->getFiltersFormContentComponent()->getColumnSpan())->toBe([
            'default' => 'full',
        ])
        ->and($dashboard->getWidgetsContentComponent()->getColumnSpan())->toBe([
            'default' => 'full',
        ]);
});

it('keeps dashboard tools and customisation out of dashboard header actions', function (): void {
    Auth::login(test()->createUserWithRole('super_admin'));

    $method = new ReflectionMethod(CapellDashboard::class, 'getHeaderActions');
    /** @var array<int, Action|ActionGroup> $actions */
    $actions = $method->invoke(new CapellDashboard);

    expect(collect($actions)->contains(
        fn (Action|ActionGroup $action): bool => $action instanceof ActionGroup
            && array_intersect(array_keys($action->getFlatActions()), [
                'buildFrontend',
                'rebuildSite',
            ]) !== [],
    ))->toBeFalse()
        ->and(collect($actions)->contains(
            fn (Action|ActionGroup $action): bool => $action instanceof Action
                && $action->getName() === 'customiseDashboard',
        ))->toBeFalse();
});

/**
 * @param  list<class-string<Widget>|string>  $widgetClasses
 * @param  array<string, int|string>  $expectedColumnSpan
 */
function assertOptionalDashboardFilamentWidgetsKeepNativeColumnSpan(array $widgetClasses, array $expectedColumnSpan): void
{
    foreach ($widgetClasses as $widgetClass) {
        if (! class_exists($widgetClass)) {
            continue;
        }

        if (! is_a($widgetClass, Widget::class, true)) {
            continue;
        }

        expect((new $widgetClass)->getColumnSpan())->toBe($expectedColumnSpan);
    }
}
