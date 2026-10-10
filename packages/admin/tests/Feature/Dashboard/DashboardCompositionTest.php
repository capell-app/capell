<?php

declare(strict_types=1);

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\DashboardRegionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Settings\AdminSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

uses(CreatesAdminUser::class);

it('omits regions whose widgets cannot be viewed', function (): void {
    $dashboard = new CompositionDashboard;
    $dashboard->widgets = [HiddenCompositionWidget::class];

    expect(compositionChildren($dashboard->getWidgetsContentComponent()))->toHaveCount(1);
    $fallback = compositionChild($dashboard->getWidgetsContentComponent(), 0, Section::class);
    expect($fallback)->toBeInstanceOf(Section::class)
        ->and($fallback->getHeading())->toBe(__('capell-admin::dashboard.empty_heading'));
});

it('retains configured extension widgets and their properties', function (): void {
    $dashboard = new CompositionDashboard;
    $dashboard->widgets = [VisibleCompositionWidget::make(['example' => 'retained'])];
    CapellAdmin::registerDashboardPanel(DashboardRegionEnum::Pulse, VisibleCompositionWidget::class, DashboardEnum::NotInstalled);

    $regions = compositionChildren($dashboard->getWidgetsContentComponent());
    expect($regions)->toHaveCount(1)->and($regions[0])->toBeInstanceOf(Grid::class);
    $widgets = compositionChildren($regions[0]);
    expect($widgets)->toHaveCount(1)->and(compositionChild($regions[0], 0, Livewire::class)->getData()['example'])->toBe('retained');
});

it('expands the remaining lead panel when its neighbour is hidden', function (): void {
    $dashboard = new CompositionDashboard;
    $dashboard->widgets = [VisibleCompositionWidget::class, HiddenCompositionWidget::class];
    CapellAdmin::registerDashboardPanel(DashboardRegionEnum::Trends, VisibleCompositionWidget::class, DashboardEnum::NotInstalled);
    CapellAdmin::registerDashboardPanel(DashboardRegionEnum::Insights, HiddenCompositionWidget::class, DashboardEnum::NotInstalled);

    $regions = compositionChildren($dashboard->getWidgetsContentComponent());
    expect($regions)->toHaveCount(1)->and($regions[0])->toBeInstanceOf(Grid::class)
        ->and($regions[0]->getColumns())->toMatchArray(['lg' => 1]);
});

it('collapses unregistered extension panels into additional information', function (): void {
    $dashboard = new CompositionDashboard;
    $dashboard->widgets = [VisibleCompositionWidget::class];

    $regions = compositionChildren($dashboard->getWidgetsContentComponent());
    expect($regions)->toHaveCount(1)->and($regions[0])->toBeInstanceOf(Section::class)
        ->and(compositionChild($dashboard->getWidgetsContentComponent(), 0, Section::class)->isCollapsed())->toBeTrue();
});

it('keeps configured lead panels distinct within the two column overview', function (): void {
    $dashboard = new CompositionDashboard;
    $dashboard->widgets = [
        VisibleCompositionWidget::make(['example' => 'first']),
        VisibleCompositionWidget::make(['example' => 'second']),
    ];
    CapellAdmin::registerDashboardPanel(DashboardRegionEnum::Trends, VisibleCompositionWidget::class, DashboardEnum::NotInstalled);
    $lead = compositionChild($dashboard->getWidgetsContentComponent(), 0, Grid::class);
    $panels = compositionChildren($lead);
    $first = compositionChild($panels[0], 0, Livewire::class);
    $second = compositionChild($panels[1], 0, Livewire::class);

    expect($lead->getColumns())->toMatchArray(['lg' => 2])
        ->and($first->getKey(isAbsolute: false))->not->toBe($second->getKey(isAbsolute: false))
        ->and($first->getData()['example'])->toBe('first')
        ->and($second->getData()['example'])->toBe('second');
});

it('respects role report visibility in the dashboard reports menu', function (): void {
    $actor = test()->createUserWithRole('super_admin');
    test()->actingAs($actor);
    $settings = AdminSettings::instance();
    $roleNames = $actor->getRoleNames();
    $reportVisibility = $settings->enabled_reports_by_role;
    foreach ($roleNames as $roleName) {
        $reportVisibility[$roleName]['core.public_render_safety'] = false;
    }

    $settings->enabled_reports_by_role = $reportVisibility;
    $settings->save();

    $actions = (new CompositionDashboard)->headerActionsForTest();
    $groups = array_values(array_filter($actions, fn (mixed $action): bool => $action instanceof ActionGroup));

    expect($groups)->toHaveCount(1)
        ->and(array_keys($groups[0]->getFlatActions()))->toContain('siteHealth')
        ->not->toContain('report_core.public_render_safety');
});

/**
 * @template T of Component
 *
 * @param  class-string<T>  $type
 * @return T
 */
function compositionChild(Component $parent, int $index, string $type): Component
{
    $child = compositionChildren($parent)[$index] ?? null;
    throw_unless($child instanceof $type, LogicException::class, 'Unexpected dashboard component type.');

    return $child;
}

/** @return list<Component> */
function compositionChildren(Component $component): array
{
    $children = $component->getDefaultChildComponents();
    if ($children instanceof Schema) {
        $children = $children->getComponents();
    }

    return array_values(array_map(function (mixed $child): Component {
        throw_unless($child instanceof Component, LogicException::class, 'Expected a dashboard schema component.');

        return $child;
    }, $children));
}

class CompositionDashboard extends CapellDashboard
{
    /** @var list<class-string<Widget>|WidgetConfiguration> */
    public array $widgets = [];

    /** @return array<int, Action|ActionGroup> */
    public function headerActionsForTest(): array
    {
        return $this->getHeaderActions();
    }

    #[Override]
    public function getWidgets(): array
    {
        return $this->widgets;
    }
}

class VisibleCompositionWidget extends Widget
{
    #[Override]
    public static function canView(): bool
    {
        return true;
    }
}

class HiddenCompositionWidget extends Widget
{
    #[Override]
    public static function canView(): bool
    {
        return false;
    }
}
