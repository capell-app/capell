<?php

declare(strict_types=1);

use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Admin\Filament\Resources\Blueprints\Widgets\BlueprintsAlertsWidget;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('alerts');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('see livewire component on blueprints page', function (): void {
    get(ManageBlueprints::getUrl())
        ->assertSeeLivewire(BlueprintsAlertsWidget::class);
});

test('alerts when default blueprints are missing', function (): void {
    Livewire::test(BlueprintsAlertsWidget::class)
        ->assertSet('alerts', function (Collection $alerts): bool {
            $alert = $alerts['blueprints'] ?? null;

            return $alert !== null
                && $alert->type->value === AlertTypeEnum::Warning->value
                && $alert->message === __('capell-admin::message.type_missing_warning')
                && $alert->icon === 'heroicon-o-shield-exclamation';
        });
});

test('no alerts when default blueprints exist', function (): void {
    foreach (BlueprintSubjectEnum::cases() as $enum) {
        Blueprint::factory()->type($enum)->create();
    }

    Livewire::test(BlueprintsAlertsWidget::class)
        ->assertSet('alerts', fn (Collection $alerts): bool => $alerts->isEmpty());
});
