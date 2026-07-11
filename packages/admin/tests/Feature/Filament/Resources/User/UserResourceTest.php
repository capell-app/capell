<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('user');

test('admin can see users', function (): void {
    test()->actingAsAdmin();

    get(UserResource::getUrl())
        ->assertOk();
});

test('cannot see users', function (): void {
    test()->actingAsUser();

    get(UserResource::getUrl())
        ->assertForbidden();
});
