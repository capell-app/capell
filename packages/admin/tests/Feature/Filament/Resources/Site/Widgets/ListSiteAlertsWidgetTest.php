<?php

declare(strict_types=1);

use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Filament\Resources\Sites\Widgets\ListSiteAlertsWidget;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('alerts');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('see livewire component on sites list', function (): void {
    get(ListSites::getUrl())
        ->assertSeeLivewire(ListSiteAlertsWidget::class);
});

it('alerts when no sites exist', function (): void {
    Livewire::test(ListSiteAlertsWidget::class)
        ->assertSet('alerts', function (Collection $alerts): bool {
            $alert = $alerts['site'] ?? null;

            return $alert !== null
                && $alert->type->value === AlertTypeEnum::Warning->value
                && $alert->message === __('capell-admin::message.site_missing_warning')
                && $alert->action->getLabel() === __('capell-admin::button.create_site');
        });
});

it('no alerts when site exists', function (): void {
    Site::factory()->createOne();

    Livewire::test(ListSiteAlertsWidget::class)
        ->assertSet('alerts', fn (Collection $alerts): bool => $alerts->isEmpty());
});
