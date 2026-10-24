<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\CreateAdditionalInstallUsersAction;
use Capell\Core\Actions\Install\ResolveInstallUserAction;
use Capell\Core\Data\NewUserData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Capell\Core\Tests\Support\Models\RetriableInstallUser;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('rolls back a starter account on role failure and recovers without changing existing accounts', function (bool $siteScoped): void {
    config([
        'auth.providers.users.model' => HasSitePermissionsTestUser::class,
        'capell.roles.super_admin' => 'retry_install_admin',
        'permission.teams' => true,
    ]);
    $registrar = resolve(PermissionRegistrar::class);
    $previousTeams = $registrar->teams;
    $previousTeamId = $registrar->getPermissionsTeamId();
    $registrar->teams = true;
    $dispatcher = Role::getEventDispatcher();

    throw_unless($dispatcher instanceof Dispatcher, RuntimeException::class, 'The starter role regression requires model events.');

    Role::setEventDispatcher(clone $dispatcher);

    try {
        $site = Site::factory()->createOne();
        $existing = User::factory()->createOne(['email_verified_at' => null]);
        $originalAttributes = $existing->fresh()->getAttributes();
        $originalRoles = $existing->roles()->pluck('roles.id')->all();
        $roleName = $siteScoped ? 'retry_install_editor' : 'retry_install_admin';
        $roleAttempts = 0;
        Role::creating(function (Role $role) use ($roleName, &$roleAttempts): void {
            throw_if($role->name === $roleName && ++$roleAttempts === 1, RuntimeException::class, 'Transient starter role failure');
        });
        $users = [
            new NewUserData('Existing starter', $existing->email, 'replacement-password', $roleName),
            new NewUserData('Completed starter', 'completed-starter@example.test', 'completed-password'),
            new NewUserData('Retry starter', 'retry-starter@example.test', 'retry-password', $roleName),
        ];
        $create = fn (): mixed => CreateAdditionalInstallUsersAction::run($users, new NullProgressReporter, $site);

        expect($create)->toThrow(RuntimeException::class, 'Transient starter role failure');
        expect(User::query()->where('email', 'retry-starter@example.test')->exists())->toBeFalse()
            ->and(Role::query()->where('name', $roleName)->exists())->toBeFalse();
        $completedAttributes = User::query()->where('email', 'completed-starter@example.test')->sole()->getAttributes();

        $create();

        $created = HasSitePermissionsTestUser::query()->where('email', 'retry-starter@example.test')->sole();
        expect(Hash::check('retry-password', $created->password))->toBeTrue()
            ->and($siteScoped ? $created->hasRoleForSite($site, $roleName) : $created->hasRole($roleName))->toBeTrue()
            ->and($roleAttempts)->toBe(2);
        $createdAttributes = $created->getAttributes();

        $create();

        expect($existing->fresh()->getAttributes())->toBe($originalAttributes)
            ->and($existing->fresh()->roles()->pluck('roles.id')->all())->toBe($originalRoles)
            ->and(User::query()->where('email', 'completed-starter@example.test')->sole()->getAttributes())->toBe($completedAttributes)
            ->and($created->refresh()->getAttributes())->toBe($createdAttributes)
            ->and($roleAttempts)->toBe(2);
    } finally {
        Role::setEventDispatcher($dispatcher);
        $registrar->setPermissionsTeamId($previousTeamId);
        $registrar->teams = $previousTeams;
        $registrar->forgetCachedPermissions();
    }
})->with(['global admin' => false, 'site editor' => true]);

it('rolls back failed verification on the user connection and can retry account creation', function (bool $additional, bool $separateConnection, bool $verificationReturnsFalse): void {
    RetriableInstallUser::$testConnection = $separateConnection ? 'installer_atomicity' : null;
    config([
        'auth.providers.users.model' => RetriableInstallUser::class,
        'database.connections.installer_atomicity' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
    $connection = DB::connection('installer_atomicity');
    $connection->getSchemaBuilder()->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
    });
    $failVerification = true;
    RetriableInstallUser::updating(function (RetriableInstallUser $user) use (&$failVerification, $verificationReturnsFalse): ?bool {
        if ($user->isDirty('email_verified_at') && $failVerification) {
            $failVerification = false;

            if ($verificationReturnsFalse) {
                return false;
            }

            throw new RuntimeException('Verification write failed');
        }

        return null;
    });
    $data = new NewUserData('Retry user', 'retry@example.test', 'retry-password', $separateConnection ? null : 'super_admin');
    $create = fn (): mixed => $additional
        ? CreateAdditionalInstallUsersAction::run([$data], new NullProgressReporter)
        : ResolveInstallUserAction::run(null, $data, new NullProgressReporter);

    try {
        expect($create)->toThrow(RuntimeException::class, $verificationReturnsFalse ? 'Install user email verification failed.' : 'Verification write failed');
        expect(RetriableInstallUser::query()->count())->toBe(0);

        $create();

        $user = RetriableInstallUser::query()->sole();
        expect($user->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('retry-password', $user->password))->toBeTrue();

        if (! $separateConnection) {
            expect($user->hasRole('super_admin'))->toBeTrue();
        }
    } finally {
        RetriableInstallUser::flushEventListeners();
        RetriableInstallUser::$testConnection = 'installer_atomicity';
        DB::purge('installer_atomicity');
    }
})->with([
    'primary on user connection with exception' => [false, true, false],
    'starter on user connection with exception' => [true, true, false],
    'starter role initialisation with exception' => [true, false, false],
    'primary on user connection with verification veto' => [false, true, true],
    'starter on user connection with verification veto' => [true, true, true],
    'starter role initialisation with verification veto' => [true, false, true],
]);

it('returns existing user by id', function (): void {
    $user = User::factory()->createOne();
    $reporter = new NullProgressReporter;

    $resolved = ResolveInstallUserAction::run(
        userId: $user->id,
        newUser: null,
        reporter: $reporter,
    );

    expect($resolved->getKey())->toBe($user->getKey());
});

it('creates a new user from NewUserData when no userId given', function (): void {
    $reporter = new NullProgressReporter;
    $newUserData = new NewUserData(
        name: 'Test User',
        email: 'installer@test.com',
        password: 'password',
    );

    $resolved = ResolveInstallUserAction::run(
        userId: null,
        newUser: $newUserData,
        reporter: $reporter,
    );

    expect($resolved->email)->toBe('installer@test.com');

    /** @var class-string<Model> $userModel */
    $userModel = config('auth.providers.users.model');
    expect($userModel::query()->where('email', 'installer@test.com')->exists())->toBeTrue();
});

it('creates additional install users with their configured roles', function (): void {
    // permission.teams is false in production today (CAP-0532's cutover
    // hasn't happened yet); enabling it here proves this action's site-scoped
    // assignRoleForSite() call actually scopes correctly once it does, the
    // same way PagePolicyTest already does for its own CAP-0532 proof.
    // Restored in finally so the rest of this suite is unaffected.
    $originalUserModel = config('auth.providers.users.model');
    config([
        'auth.providers.users.model' => HasSitePermissionsTestUser::class,
        'permission.teams' => true,
    ]);
    resolve(PermissionRegistrar::class)->teams = true;

    try {
        $site = Site::factory()->createOne();
        $reporter = new NullProgressReporter;

        CreateAdditionalInstallUsersAction::run([
            new NewUserData(
                name: 'Example Super Admin',
                email: 'super-admin@example.test',
                password: 'password123',
                roleName: 'super_admin',
            ),
            new NewUserData(
                name: 'Example Editor',
                email: 'editor@example.test',
                password: 'password123',
                roleName: 'editor',
            ),
        ], $reporter, $site);

        $superAdmin = HasSitePermissionsTestUser::query()->where('email', 'super-admin@example.test')->firstOrFail();
        $editor = HasSitePermissionsTestUser::query()->where('email', 'editor@example.test')->firstOrFail();

        expect($superAdmin->isGlobalAdmin())->toBeTrue()
            ->and($editor->hasRoleForSite($site, 'editor'))->toBeTrue()
            ->and($editor->getRolesForSite($site)->pluck('name')->all())->toBe(['editor'])
            ->and(Hash::check('password123', $superAdmin->password))->toBeTrue()
            ->and(Hash::check('password123', $editor->password))->toBeTrue();
    } finally {
        resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
        resolve(PermissionRegistrar::class)->teams = false;
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['permission.teams' => false]);
        config(['auth.providers.users.model' => $originalUserModel]);
    }
});

it('creates an additional install user with the Shield super admin role when the Capell role is not configured', function (): void {
    $originalRoles = config('capell.roles');
    $originalShieldRole = config('filament-shield.super_admin.name');
    $roles = is_array($originalRoles) ? $originalRoles : [];
    unset($roles['super_admin']);

    config([
        'capell.roles' => $roles,
        'filament-shield.super_admin.name' => 'shield-super-admin',
    ]);

    try {
        CreateAdditionalInstallUsersAction::run([
            new NewUserData(
                name: 'Shield Super Admin',
                email: 'shield-super-admin@example.test',
                password: 'password123',
                roleName: 'shield-super-admin',
            ),
        ], new NullProgressReporter);

        $user = User::query()->where('email', 'shield-super-admin@example.test')->firstOrFail();

        expect($user->hasRole('shield-super-admin'))->toBeTrue();
    } finally {
        config([
            'capell.roles' => $originalRoles,
            'filament-shield.super_admin.name' => $originalShieldRole,
        ]);
    }
});

it('throws when userId given but user does not exist', function (): void {
    $reporter = new NullProgressReporter;

    expect(fn (): mixed => ResolveInstallUserAction::run(
        userId: 99999,
        newUser: null,
        reporter: $reporter,
    ))->toThrow(RuntimeException::class);
});

it('preserves existing accounts when preparing starter role users including retries', function (): void {
    $user = User::factory()->createOne([
        'name' => 'Existing owner',
        'email' => 'super-admin@example.test',
        'password' => Hash::make('original-password'),
        'email_verified_at' => null,
    ]);
    $attributes = $user->refresh()->getAttributes();
    $roles = $user->roles()->pluck('roles.id')->all();

    for ($attempt = 0; $attempt < 2; $attempt++) {
        CreateAdditionalInstallUsersAction::run([
            new NewUserData('Starter admin', $user->email, 'replacement-password', 'super_admin'),
            new NewUserData('New editor', 'new-editor@example.test', 'editor-password'),
        ], new NullProgressReporter);
    }

    expect($user->fresh()->getAttributes())->toBe($attributes)
        ->and($user->fresh()->roles()->pluck('roles.id')->all())->toBe($roles)
        ->and(User::query()->where('email', 'new-editor@example.test')->count())->toBe(1);
});

it('rejects a new primary install user that collides with an existing account', function (): void {
    $user = User::factory()->createOne(['email_verified_at' => null]);
    $attributes = $user->refresh()->getAttributes();

    expect(fn (): mixed => ResolveInstallUserAction::run(
        null,
        new NewUserData('Replacement', $user->email, 'replacement-password'),
        new NullProgressReporter,
    ))->toThrow(ValidationException::class);

    expect($user->fresh()->getAttributes())->toBe($attributes);
});
