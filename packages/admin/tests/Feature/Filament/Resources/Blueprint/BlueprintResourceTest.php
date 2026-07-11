<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Support\Icons\Heroicon;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('type');

test('admin can see blueprints', function (): void {
    test()->actingAsAdmin();

    get(BlueprintResource::getUrl())
        ->assertOk();
});

test('cannot see blueprints', function (): void {
    test()->actingAsUser();

    get(BlueprintResource::getUrl())
        ->assertForbidden();
});

test('uses blueprint labels', function (): void {
    test()->actingAsAdmin();

    get(BlueprintResource::getUrl())
        ->assertOk();

    expect(BlueprintResource::getNavigationLabel())->toBe((string) __('capell-admin::navigation.blueprints'))
        ->and(BlueprintResource::getModelLabel())->toBe((string) __('capell-admin::generic.blueprint'))
        ->and(BlueprintResource::getPluralModelLabel())->toBe((string) __('capell-admin::generic.blueprints'))
        ->and(BlueprintResource::getNavigationIcon())->toBe(Heroicon::OutlinedDocumentDuplicate)
        ->and(BlueprintResource::getActiveNavigationIcon())->toBe(Heroicon::DocumentDuplicate);
});
