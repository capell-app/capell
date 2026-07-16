<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('user');

it('admin can see users', function (): void {
    test()->actingAsAdmin();

    get(UserResource::getUrl())
        ->assertOk();
});

it('cannot see users', function (): void {
    test()->actingAsUser();

    get(UserResource::getUrl())
        ->assertForbidden();
});
