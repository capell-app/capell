<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\ResolvePreferredAdminLanguageIdAction;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Core\Models\Language;

it('resolves the preferred admin language id from a fully loaded user', function (): void {
    $language = Language::factory()->english()->create();
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);

    expect(ResolvePreferredAdminLanguageIdAction::run($user))->toBe($language->getKey());
});

it('resolves the preferred admin language id from a partially loaded user', function (): void {
    $language = Language::factory()->english()->create();
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);
    $partialUser = $user->newQuery()->select(['id', 'name', 'email'])->whereKey($user->getKey())->firstOrFail();

    expect(ResolvePreferredAdminLanguageIdAction::run($partialUser))->toBe($language->getKey());
});
