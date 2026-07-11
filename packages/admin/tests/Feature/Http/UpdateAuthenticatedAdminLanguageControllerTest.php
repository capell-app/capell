<?php

declare(strict_types=1);

use Capell\Core\Database\Factories\UserFactory;
use Capell\Core\Models\Language;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\post;

uses(CreatesAdminUser::class);

it('updates the authenticated users preferred admin language', function (): void {
    $language = Language::factory()->english()->create(['status' => true]);
    $user = test()->createUserWithRole('super_admin');

    test()->actingAs($user);

    post(route('capell-admin.profile.language.update'), [
        'preferred_admin_language_id' => $language->getKey(),
    ])->assertRedirect();

    assertDatabaseHas('users', [
        'id' => $user->getKey(),
        'preferred_admin_language_id' => $language->getKey(),
    ]);
});

it('rejects disabled admin languages', function (): void {
    $language = Language::factory()->english()->create(['status' => false]);
    test()->actingAsAdmin();

    post(route('capell-admin.profile.language.update'), [
        'preferred_admin_language_id' => $language->getKey(),
    ])->assertSessionHasErrors('preferred_admin_language_id');
});

it('rejects malformed admin language values', function (): void {
    test()->actingAsAdmin();

    post(route('capell-admin.profile.language.update'), [
        'preferred_admin_language_id' => 'english',
    ])->assertSessionHasErrors('preferred_admin_language_id');
});

it('clears the authenticated users preferred admin language', function (): void {
    $language = Language::factory()->english()->create(['status' => true]);
    $user = test()->createUserWithRole('super_admin', [
        'preferred_admin_language_id' => $language->getKey(),
    ]);

    test()->actingAs($user);

    post(route('capell-admin.profile.language.update'), [
        'preferred_admin_language_id' => '',
    ])->assertRedirect();

    assertDatabaseHas('users', [
        'id' => $user->getKey(),
        'preferred_admin_language_id' => null,
    ]);
});

it('rejects authenticated users who cannot access the admin panel', function (): void {
    $language = Language::factory()->english()->create(['status' => true]);
    $user = UserFactory::new()->createOne();

    test()->actingAs($user);

    post(route('capell-admin.profile.language.update'), [
        'preferred_admin_language_id' => $language->getKey(),
    ])->assertForbidden();
});
