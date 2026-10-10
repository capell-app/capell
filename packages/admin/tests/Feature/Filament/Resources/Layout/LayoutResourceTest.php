<?php

declare(strict_types=1);

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Core\Models\Layout;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('layout');

it('admin can see layouts', function (): void {
    test()->actingAsAdmin();

    get(LayoutResource::getUrl())
        ->assertOk();
});

it('cannot see layouts', function (): void {
    test()->actingAsUser();

    get(LayoutResource::getUrl())
        ->assertForbidden();
});

it('admin can see create layout', function (): void {
    test()->actingAsAdmin();

    get(LayoutResource::getUrl('create'))->assertOk();
});

it('admin can see edit layout', function (): void {
    test()->actingAsAdmin();

    get(LayoutResource::getUrl('edit', ['record' => Layout::factory()->createOne()]))->assertOk();
});

it('shows one layout navigation entry when a specialised editor is registered', function (): void {
    test()->actingAsAdmin();
    $specialised = new class extends LayoutResource {};

    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(
            class: $specialised::class,
            group: 'Layout',
            name: 'specialised-editor',
        ),
    );

    expect(LayoutResource::shouldRegisterNavigation())->toBeFalse()
        ->and($specialised::shouldRegisterNavigation())->toBeTrue();
});

it('keeps the layout navigation entry without a specialised editor', function (): void {
    test()->actingAsAdmin();
    expect(LayoutResource::shouldRegisterNavigation())->toBeTrue();
});

it('keeps the layout navigation entry when the specialised editor is inaccessible', function (): void {
    test()->actingAsAdmin();
    $specialised = new class extends LayoutResource
    {
        #[Override]
        public static function canViewAny(): bool
        {
            return false;
        }
    };

    CapellAdmin::contributeToAdminSurface(
        AdminSurfaceContributionData::resource(
            class: $specialised::class,
            group: 'Layout',
            name: 'inaccessible-editor',
        ),
    );

    expect(LayoutResource::shouldRegisterNavigation())->toBeTrue();
});
