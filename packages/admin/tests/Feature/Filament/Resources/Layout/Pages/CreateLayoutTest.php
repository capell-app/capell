<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Core\Models\Layout;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(CreatesAdminUser::class)
    ->group('layout');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

describe('from edit page', function (): void {
    test('can create new layout', function (): void {
        $layout = Layout::factory()->createOne();

        $newData = Layout::factory()->make();

        Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
            ->assertSuccessful()
            ->callAction(CreateAction::class, data: [
                'name' => $newData->name,
                'key' => $newData->key,
            ]);

        assertDatabaseHas(Layout::class, [
            'name' => $newData->name,
            'key' => $newData->key,
        ]);
    });

    test('required fields are required', function (): void {
        $layout = Layout::factory()->createOne();

        Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
            ->assertSuccessful()
            ->callAction(CreateAction::class, [
                'name' => '',
                'key' => '',
            ])
            ->assertHasFormErrors([
                'name' => 'required',
                'key' => 'required',
            ]);
    });
});

describe('from list page', function (): void {
    test('can create new layout', function (): void {
        $newData = Layout::factory()->make();

        Livewire::test(ListLayouts::class)
            ->assertSuccessful()
            ->callAction(CreateAction::class, [
                'name' => $newData->name,
                'key' => $newData->key,
            ])
            ->assertRedirectToRoute(EditLayout::getRouteName(), ['record' => Layout::query()->latest()->firstOrFail()->getRouteKey()]);

        assertDatabaseHas(Layout::class, [
            'name' => $newData->name,
            'key' => $newData->key,
        ]);
    });

    test('required fields are required', function (): void {
        Livewire::test(ListLayouts::class)
            ->assertSuccessful()
            ->callAction(CreateAction::class, [
                'name' => '',
                'key' => '',
            ])
            ->assertHasFormErrors([
                'name' => 'required',
                'key' => 'required',
            ]);
    });
});
