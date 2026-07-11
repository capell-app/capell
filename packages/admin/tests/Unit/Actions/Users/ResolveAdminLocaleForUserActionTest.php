<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\ResolveAdminLocaleForUserAction;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Core\Models\Language;
use Capell\Tests\Fixtures\Models\User;

it('resolves the preferred admin language locale for a user', function (): void {
    $language = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls')->create(['status' => true]);
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);

    expect(ResolveAdminLocaleForUserAction::run($user))->toBe('cy');
});

it('falls back to the language code when locale is blank', function (): void {
    $language = Language::factory()->forCountry('Cymraeg', '', 'cy', 'gb-wls')->create(['status' => true]);
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);

    expect(ResolveAdminLocaleForUserAction::run($user))->toBe('cy');
});

it('falls back to the app locale when the preference is missing or disabled', function (): void {
    config()->set('app.locale', 'en');

    $disabledLanguage = Language::factory()->french()->create(['status' => false]);
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $disabledLanguage->getKey()]);

    expect(ResolveAdminLocaleForUserAction::run($user))->toBe('en');
});

it('resolves the preferred admin locale when the auth user was loaded with selected columns', function (): void {
    $language = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls')->create(['status' => true]);
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);
    $partialUser = $user->newQuery()->select(['id', 'name', 'email'])->whereKey($user->getKey())->firstOrFail();

    expect(ResolveAdminLocaleForUserAction::run($partialUser))->toBe('cy');
});

it('falls back when the user model has not loaded the preferred admin language attribute', function (): void {
    config()->set('app.locale', 'en');

    UserFactory::new()->createOne();
    $user = User::query()
        ->select(['id', 'name', 'email'])
        ->firstOrFail();

    expect(ResolveAdminLocaleForUserAction::run($user))->toBe('en');
});
