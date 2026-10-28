<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\AttentionWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\ContentStatusWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PageViewsWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PersonalDraftsWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\UrgentSummaryWidget;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Widgets\WidgetConfiguration;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('saves an allowed personal order and rejects tampered widget keys', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $user = test()->authenticatedUser();
    $classes = array_values(array_map(
        static fn (string|WidgetConfiguration $widget): string => $widget instanceof WidgetConfiguration ? $widget->widget : $widget,
        array_filter((new CapellDashboard)->getWidgets(), static function (string|WidgetConfiguration $widget): bool {
            $class = $widget instanceof WidgetConfiguration ? $widget->widget : $widget;

            return $class !== UrgentSummaryWidget::class && $class::canView();
        }),
    ));
    $order = array_reverse($classes);
    $form = new ReflectionMethod(CapellDashboard::class, 'layoutFormData')->invoke(new CapellDashboard);
    $form['widgets'] = array_map(static fn (string $key): array => ['key' => $key, 'size' => $key === PersonalDraftsWidget::class ? 'wide' : 'standard'], $order);
    $form['selected_performance'] = array_values(array_diff($form['selected_performance'], [PageViewsWidget::class]));
    Livewire::test(CapellDashboard::class)->callAction('editWorkFirstLayout', data: $form);

    $store = resolve(AdminWorkspacePreferenceStore::class);
    expect($store->dashboardLayout($user, true))->toBe($order)
        ->and($store->dashboardOptions($user, true)['hidden'])->toContain(PageViewsWidget::class)
        ->and($store->dashboardOptions($user, true)['sizes'][PersonalDraftsWidget::class])->toBe('wide');
    $widget = new PersonalDraftsWidget;
    $widget->dashboardSize = 'wide';

    expect($widget->getColumnSpan())->toBe(['default' => 'full', 'lg' => 'full']);

    $tampered = $order;
    $tampered[0] = 'Not\\A\\Widget';
    Livewire::test(CapellDashboard::class)->callAction('editWorkFirstLayout', data: [
        'widgets' => array_map(static fn (string $key): array => ['key' => $key], $tampered),
    ]);
    expect($store->dashboardLayout($user, true))->toBe($order);
});

it('does not save a changed layout when editing is cancelled', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $user = test()->authenticatedUser();
    $component = Livewire::test(CapellDashboard::class)->mountAction('editWorkFirstLayout');
    $component->set('mountedActions.0.data.widgets', [
        ['key' => PersonalDraftsWidget::class, 'visible' => false, 'size' => 'wide'],
    ])->unmountAction();

    $store = resolve(AdminWorkspacePreferenceStore::class);
    expect($store->dashboardLayout($user, true))->toBeNull()
        ->and($store->dashboardOptions($user, true))->toBe(['hidden' => [], 'sizes' => []]);
});

it('keeps publishing blockers self-contained when attention is hidden', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $user = test()->authenticatedUser();
    $store = resolve(AdminWorkspacePreferenceStore::class);
    $dashboard = new CapellDashboard;
    $visible = new ReflectionMethod($dashboard, 'isWorkFirstAttentionVisible');
    expect($visible->invoke($dashboard))->toBeTrue();

    $attention = AttentionWidget::class;
    $store->saveDashboardPreferences($user, true, [$attention], [$attention], []);
    expect($visible->invoke($dashboard))->toBeFalse();
    $store->saveDashboardPreferences($user, true, [$attention], [], []);
    expect($visible->invoke($dashboard))->toBeTrue();
    config()->set('capell-admin.dashboard.work_first.widgets', ['attention' => false]);
    expect($visible->invoke($dashboard))->toBeFalse();
});

it('restores defaults only when the editor is saved', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $user = test()->authenticatedUser();
    $store = resolve(AdminWorkspacePreferenceStore::class);
    $store->saveDashboardPreferences($user, true, [PersonalDraftsWidget::class], [], [PersonalDraftsWidget::class => 'wide']);

    $component = Livewire::test(CapellDashboard::class)->mountAction('editWorkFirstLayout');
    $component->set('mountedActions.0.data.restore_defaults', true)->unmountAction();
    expect($store->dashboardLayout($user, true))->toBe([PersonalDraftsWidget::class]);

    Livewire::test(CapellDashboard::class)->callAction('editWorkFirstLayout', data: ['restore_defaults' => true]);
    expect($store->dashboardLayout($user, true))->toBeNull()
        ->and($store->dashboardOptions($user, true))->toBe(['hidden' => [], 'sizes' => []]);
});

it('adds an optional content widget from its group and rejects cross-group tampering', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $user = test()->authenticatedUser();
    $store = resolve(AdminWorkspacePreferenceStore::class);
    $form = new ReflectionMethod(CapellDashboard::class, 'layoutFormData')->invoke(new CapellDashboard);
    $form['selected_content'][] = ContentStatusWidget::class;
    Livewire::test(CapellDashboard::class)->callAction('editWorkFirstLayout', data: $form);
    expect($store->dashboardOptions($user, true)['hidden'])->not->toContain(ContentStatusWidget::class);
    $saved = $store->dashboardLayout($user, true);

    $form['selected_performance'][] = PersonalDraftsWidget::class;
    Livewire::test(CapellDashboard::class)->callAction('editWorkFirstLayout', data: $form);
    expect($store->dashboardLayout($user, true))->toBe($saved);
});
