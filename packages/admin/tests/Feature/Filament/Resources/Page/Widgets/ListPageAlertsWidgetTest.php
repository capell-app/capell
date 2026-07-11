<?php

declare(strict_types=1);

use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Widgets\ListPageAlertsWidget;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('see livewire component', function (): void {
    get(PageResource::getUrl())
        ->assertSeeLivewire(ListPageAlertsWidget::class);
});

test('alerts when no sites exist', function (): void {
    Livewire::test(ListPageAlertsWidget::class)
        ->assertSet('alerts', function (Collection $alerts): bool {
            $alert = $alerts['site'];

            return $alert->type->value === AlertTypeEnum::Warning->value
                && $alert->message === __('capell-admin::message.site_missing_warning')
                && $alert->action->getLabel() === __('capell-admin::button.create_site');
        });
});

test('no alerts when site exists', function (): void {
    Site::factory()->createOne();

    Livewire::test(ListPageAlertsWidget::class)
        ->assertSet('alerts', fn (Collection $alerts): bool => $alerts->isEmpty());
});
