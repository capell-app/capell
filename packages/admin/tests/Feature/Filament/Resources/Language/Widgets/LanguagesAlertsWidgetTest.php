<?php

declare(strict_types=1);

use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Admin\Filament\Resources\Languages\Widgets\LanguagesAlertsWidget;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('alerts');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('see livewire component on languages page', function (): void {
    get(ManageLanguages::getUrl())
        ->assertSeeLivewire(LanguagesAlertsWidget::class);
});

test('alerts when default language is missing', function (): void {
    Blueprint::factory()->site()->create();

    Livewire::test(LanguagesAlertsWidget::class)
        ->assertSet('alerts', function (Collection $alerts): bool {
            $alert = $alerts['language'] ?? null;

            return $alert !== null
                && $alert->type->value === AlertTypeEnum::Warning->value
                && $alert->message === __('capell-admin::message.language_missing_warning')
                && $alert->icon === 'heroicon-o-shield-exclamation';
        });
});

test('no alerts when default language exists', function (): void {
    Language::factory()->default()->create();

    Livewire::test(LanguagesAlertsWidget::class)
        ->assertSet('alerts', fn (Collection $alerts): bool => $alerts->isEmpty());
});
