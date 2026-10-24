<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\CreateAdditionalInstallUsersAction;
use Capell\Core\Actions\Install\ResolveInstallUserAction;
use Capell\Core\Data\NewUserData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RetriableInstallUser extends User implements Illuminate\Contracts\Auth\MustVerifyEmail
{
    use MustVerifyEmail;

    public static ?string $testConnection = 'installer_atomicity';

    protected $table = 'users';

    #[Override]
    public function getConnectionName(): ?string
    {
        return self::$testConnection;
    }

    #[Override]
    public function getMorphClass(): string
    {
        return 'user';
    }
}

it('rolls back failed verification on the user connection and can retry account creation', function (bool $additional, bool $separateConnection): void {
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
    RetriableInstallUser::updating(function (RetriableInstallUser $user) use (&$failVerification): void {
        if ($user->isDirty('email_verified_at') && $failVerification) {
            $failVerification = false;

            throw new RuntimeException('Verification write failed');
        }
    });
    $data = new NewUserData('Retry user', 'retry@example.test', 'retry-password', $separateConnection ? null : 'super_admin');
    $create = fn (): mixed => $additional
        ? CreateAdditionalInstallUsersAction::run([$data], new NullProgressReporter)
        : ResolveInstallUserAction::run(null, $data, new NullProgressReporter);

    try {
        expect($create)->toThrow(RuntimeException::class, 'Verification write failed');
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
    'primary on user connection' => [false, true],
    'starter on user connection' => [true, true],
    'starter role initialisation' => [true, false],
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
