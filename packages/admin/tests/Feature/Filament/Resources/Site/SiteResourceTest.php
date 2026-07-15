<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('site');

it('admin can see sites', function (): void {
    test()->actingAsAdmin();

    get(SiteResource::getUrl())
        ->assertOk();
});

it('cannot see sites', function (): void {
    test()->actingAsUser();

    get(SiteResource::getUrl())
        ->assertForbidden();
});

it('admin can see create site', function (): void {
    test()->actingAsAdmin();
    Blueprint::factory()->site()->default()->create();

    get(SiteResource::getUrl('create'))->assertOk();
});

it('admin can see edit site', function (): void {
    test()->actingAsAdmin();

    get(SiteResource::getUrl('edit', ['record' => Site::factory()->createOne()]))->assertOk();
});

it('sites appear before languages in website navigation group', function (): void {
    expect(SiteResource::getNavigationItems()[0]->getSort())
        ->toBeLessThan(LanguageResource::getNavigationItems()[0]->getSort());
});
