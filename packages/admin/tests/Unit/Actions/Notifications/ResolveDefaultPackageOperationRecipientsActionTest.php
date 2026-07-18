<?php

declare(strict_types=1);

use Capell\Admin\Actions\Notifications\ResolveDefaultPackageOperationRecipientsAction;
use Capell\Admin\Tests\Fixtures\Models\PackageOperationRecipientFallbackUser;
use Capell\Admin\Tests\Fixtures\Models\PackageOperationRecipientRoleAwareUser;
use Capell\Admin\Tests\Fixtures\Models\PackageOperationRecipientScopeOnlyUser;
use Capell\Core\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

it('queries and returns both global and configured admins in user order for role-aware models', function (): void {
    config()->set('filament-shield.super_admin.name', 'shield-admin');
    config()->set('capell.roles.super_admin', 'capell-admin');
    config()->set('auth.providers.users.model', PackageOperationRecipientRoleAwareUser::class);
    Relation::morphMap(['PackageOperationRecipientRoleAwareUser' => PackageOperationRecipientRoleAwareUser::class]);

    Role::findOrCreate('shield-admin');
    Role::findOrCreate('capell-admin');
    Role::findOrCreate('editor');

    $createUser = static function (): PackageOperationRecipientRoleAwareUser {
        $user = UserFactory::new()->createOne();

        return PackageOperationRecipientRoleAwareUser::query()->whereKey($user->getKey())->firstOrFail();
    };

    $firstAdmin = $createUser()->assignRole('shield-admin');
    $createUser()->assignRole('editor');
    $secondAdmin = $createUser()->assignRole('capell-admin');
    UserFactory::new()->createOne();

    $userQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$userQueries): void {
        if (preg_match('/from ["`]?users["`]?/i', $query->sql) === 1) {
            $userQueries[] = $query->sql;
        }
    });

    $recipients = ResolveDefaultPackageOperationRecipientsAction::run();

    expect($recipients->modelKeys())->toBe([
        $firstAdmin->getKey(),
        $secondAdmin->getKey(),
    ])->and($userQueries)->toHaveCount(1)
        ->and(strtolower($userQueries[0]))->toContain('exists')
        ->and(strtolower($userQueries[0]))->toContain('order by "users"."id"');
});

it('preserves method-tolerant filtering and user order for models without a role scope', function (): void {
    $firstAdmin = UserFactory::new()->createOne(['email' => 'first@admin.example.test']);
    UserFactory::new()->createOne(['email' => 'member@example.test']);
    $secondAdmin = UserFactory::new()->createOne(['email' => 'second@admin.example.test']);
    UserFactory::new()->createOne(['email' => 'another@example.test']);

    config()->set('auth.providers.users.model', PackageOperationRecipientFallbackUser::class);

    expect(ResolveDefaultPackageOperationRecipientsAction::run()->modelKeys())->toBe([
        $firstAdmin->getKey(),
        $secondAdmin->getKey(),
    ]);
});

it('does not optimize scope-only models or allow their scope to broaden recipients', function (): void {
    config()->set('capell.roles.super_admin', 'capell-admin');
    config()->set('auth.providers.users.model', PackageOperationRecipientScopeOnlyUser::class);
    Relation::morphMap(['PackageOperationRecipientScopeOnlyUser' => PackageOperationRecipientScopeOnlyUser::class]);

    Role::findOrCreate('shield-admin');
    Role::findOrCreate('capell-admin');

    $createUser = static function (): PackageOperationRecipientScopeOnlyUser {
        $user = UserFactory::new()->createOne();

        return PackageOperationRecipientScopeOnlyUser::query()->whereKey($user->getKey())->firstOrFail();
    };

    $firstAdmin = $createUser()->assignRole('capell-admin');
    $createUser()->assignRole('shield-admin');
    $secondAdmin = $createUser()->assignRole('capell-admin');

    $userQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$userQueries): void {
        if (preg_match('/from ["`]?users["`]?/i', $query->sql) === 1) {
            $userQueries[] = strtolower($query->sql);
        }
    });

    expect(ResolveDefaultPackageOperationRecipientsAction::run()->modelKeys())->toBe([
        $firstAdmin->getKey(),
        $secondAdmin->getKey(),
    ])->and($userQueries)->toHaveCount(1)
        ->and($userQueries[0])->not->toContain('exists')
        ->and($userQueries[0])->toContain('order by "users"."id"');
});
