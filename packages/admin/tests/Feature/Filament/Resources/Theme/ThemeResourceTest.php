<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('theme');

test('admin can see themes', function (): void {
    test()->actingAsAdmin();

    get(ThemeResource::getUrl())
        ->assertOk();
});

test('cannot see themes', function (): void {
    test()->actingAsUser();

    get(ThemeResource::getUrl())
        ->assertForbidden();
});
