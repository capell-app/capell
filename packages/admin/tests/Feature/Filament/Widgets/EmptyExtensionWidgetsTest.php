<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionUpdateReadinessAction;
use Capell\Admin\Actions\Extensions\ListExtensionAuditEventsAction;
use Capell\Admin\Data\Extensions\ExtensionAuditEventData;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionUpdateReadinessFilamentWidget;
use Capell\Admin\Filament\Widgets\Extensions\RecentlyChangedExtensionsFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    $this->actingAs($this->createUser());
    app()->instance('LaravelActions:AsFake:' . BuildExtensionUpdateReadinessAction::class, Mockery::mock(new BuildExtensionUpdateReadinessAction));
    app()->instance('LaravelActions:AsFake:' . ListExtensionAuditEventsAction::class, Mockery::mock(new ListExtensionAuditEventsAction));
});

it('hides update readiness when there are no rows to display', function (array $updates): void {
    BuildExtensionUpdateReadinessAction::shouldRun()->andReturn($updates);

    expect(ExtensionUpdateReadinessFilamentWidget::canView())->toBeFalse();
})->with([
    'no packages' => [[]],
    'no updates' => [[new ExtensionUpdateReadinessData('capell-app/core', 'none')]],
]);

it('shows actionable update readiness rows', function (): void {
    BuildExtensionUpdateReadinessAction::shouldRun()->andReturn([
        new ExtensionUpdateReadinessData('capell-app/core', 'none'),
        new ExtensionUpdateReadinessData('capell-app/frontend', 'blocked'),
    ]);

    expect(ExtensionUpdateReadinessFilamentWidget::canView())->toBeTrue();
    Livewire::test(ExtensionUpdateReadinessFilamentWidget::class)->assertOk()->assertSee('capell-app/frontend');
});

it('hides recent extension changes when there are no events', function (): void {
    ListExtensionAuditEventsAction::shouldRun()->andReturn([]);

    expect(RecentlyChangedExtensionsFilamentWidget::canView())->toBeFalse();
});

it('shows recent extension events', function (): void {
    ListExtensionAuditEventsAction::shouldRun()->andReturn([
        new ExtensionAuditEventData('installed-core', 'capell-app/core', 'installed', now()->toImmutable()),
    ]);

    expect(RecentlyChangedExtensionsFilamentWidget::canView())->toBeTrue();
    Livewire::test(RecentlyChangedExtensionsFilamentWidget::class)->assertOk()->assertSee('capell-app/core');
});

it('preserves settings and guest gating without querying extension data', function (): void {
    BuildExtensionUpdateReadinessAction::shouldRun()->never();
    ListExtensionAuditEventsAction::shouldRun()->never();
    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = [...$settings->enabled_widgets, 'extensions.update_readiness' => false, 'extensions.recently_changed' => false];
    $settings->save();

    expect(ExtensionUpdateReadinessFilamentWidget::canView())->toBeFalse()
        ->and(RecentlyChangedExtensionsFilamentWidget::canView())->toBeFalse();

    auth()->logout();

    expect(ExtensionUpdateReadinessFilamentWidget::canView())->toBeFalse()
        ->and(RecentlyChangedExtensionsFilamentWidget::canView())->toBeFalse();
});
