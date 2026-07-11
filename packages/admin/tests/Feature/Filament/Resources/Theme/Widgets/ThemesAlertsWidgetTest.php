<?php

declare(strict_types=1);

use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Admin\Filament\Resources\Themes\Widgets\ThemesAlertsWidget;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('alerts');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('see livewire component on themes page', function (): void {
    get(ManageThemes::getUrl())
        ->assertSeeLivewire(ThemesAlertsWidget::class);
});

test('alerts when default theme is missing', function (): void {
    Blueprint::factory()->site()->create();
    Blueprint::factory()->theme()->create();

    Livewire::test(ThemesAlertsWidget::class)
        ->assertSet('alerts', function (Collection $alerts): bool {
            $alert = $alerts['theme'] ?? null;

            return $alert !== null
                && $alert->type->value === AlertTypeEnum::Warning->value
                && $alert->message === __('capell-admin::message.theme_missing_warning')
                && $alert->icon === 'heroicon-o-shield-exclamation';
        });
});

test('no alerts when default theme exists', function (): void {
    $themeType = Blueprint::factory()->theme()->create();
    Theme::factory()->state(['blueprint_id' => $themeType->id, 'default' => true])->create();

    Livewire::test(ThemesAlertsWidget::class)
        ->assertSet('alerts', fn (Collection $alerts): bool => $alerts->isEmpty());
});
