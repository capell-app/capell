<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Widgets;

use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Tests\Feature\Filament\Widgets\Fixtures\FixtureAlertsFilamentWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('widget');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('renders alerts returned by buildAlerts', function (): void {
    Livewire::test(FixtureAlertsFilamentWidget::class)
        ->assertSet('alerts', function (Collection $alerts): bool {
            $alert = $alerts['test'] ?? null;

            return $alert !== null
                && $alert->message === 'Test alert message'
                && $alert->type->value === AlertTypeEnum::Warning->value
                && $alert->icon === 'heroicon-o-shield-exclamation';
        });
});

it('refreshAlerts clears computed alerts cache', function (): void {
    $widget = Livewire::test(FixtureAlertsFilamentWidget::class);

    $widget->dispatch('refresh-alerts');

    $widget->assertSet('alerts', fn (Collection $alerts): bool => $alerts->has('test'));
});
