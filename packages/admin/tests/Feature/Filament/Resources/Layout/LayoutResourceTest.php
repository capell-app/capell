<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Core\Models\Layout;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('layout');

test('admin can see layouts', function (): void {
    test()->actingAsAdmin();

    get(LayoutResource::getUrl())
        ->assertOk();
});

test('cannot see layouts', function (): void {
    test()->actingAsUser();

    get(LayoutResource::getUrl())
        ->assertForbidden();
});

test('admin can see create layout', function (): void {
    test()->actingAsAdmin();

    get(LayoutResource::getUrl('create'))->assertOk();
});

test('admin can see edit layout', function (): void {
    test()->actingAsAdmin();

    get(LayoutResource::getUrl('edit', ['record' => Layout::factory()->createOne()]))->assertOk();
});
