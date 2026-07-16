<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('language');

it('admin can see languages', function (): void {
    test()->actingAsAdmin();

    get(LanguageResource::getUrl())
        ->assertOk();
});

it('cannot see languages', function (): void {
    test()->actingAsUser();

    get(LanguageResource::getUrl())
        ->assertForbidden();
});
