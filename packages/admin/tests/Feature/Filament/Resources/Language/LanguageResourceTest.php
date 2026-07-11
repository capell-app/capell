<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('language');

test('admin can see languages', function (): void {
    test()->actingAsAdmin();

    get(LanguageResource::getUrl())
        ->assertOk();
});

test('cannot see languages', function (): void {
    test()->actingAsUser();

    get(LanguageResource::getUrl())
        ->assertForbidden();
});
