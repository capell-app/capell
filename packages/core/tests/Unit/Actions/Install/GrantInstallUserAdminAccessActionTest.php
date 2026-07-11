<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\GrantInstallUserAdminAccessAction;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

it('grants the configured super admin role to the installer user', function (): void {
    config(['capell.roles.super_admin' => 'super_admin']);

    $user = User::factory()->createOne();

    GrantInstallUserAdminAccessAction::run($user, new NullProgressReporter);

    expect(Role::query()->where('name', 'super_admin')->exists())->toBeTrue();
    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('grants the configured super admin role when the loaded user model does not have spatie role methods', function (): void {
    config(['capell.roles.super_admin' => 'super_admin']);

    $user = new class extends AuthenticatableUser
    {
        use HasFactory;

        protected $table = 'users';

        protected $guarded = [];
    };
    $user->forceFill([
        'name' => 'Install User',
        'email' => 'install@example.test',
        'password' => 'password',
    ])->save();

    GrantInstallUserAdminAccessAction::run($user, new NullProgressReporter);

    $role = Role::query()->where('name', 'super_admin')->firstOrFail();

    expect(DB::table('model_has_roles')
        ->where('role_id', $role->getKey())
        ->where('model_type', $user::class)
        ->where('model_id', $user->getKey())
        ->exists())->toBeTrue();
});

it('stores the morph alias (not the FQCN) when assigning the role directly', function (): void {
    config(['capell.roles.super_admin' => 'super_admin']);

    $user = new class extends AuthenticatableUser
    {
        use HasFactory;

        protected $table = 'users';

        protected $guarded = [];
    };
    $user->forceFill([
        'name' => 'Install User',
        'email' => 'morph@example.test',
        'password' => 'password',
    ])->save();

    // Register a morph alias so the runtime resolves role assignments against
    // the alias, not the class name. Storing the FQCN here would never match.
    $originalMorphMap = Relation::morphMap();

    try {
        Relation::morphMap(['install-user-alias' => $user::class]);

        GrantInstallUserAdminAccessAction::run($user, new NullProgressReporter);

        $role = Role::query()->where('name', 'super_admin')->firstOrFail();

        expect(DB::table('model_has_roles')
            ->where('role_id', $role->getKey())
            ->where('model_type', 'install-user-alias')
            ->where('model_id', $user->getKey())
            ->exists())->toBeTrue();

        expect(DB::table('model_has_roles')
            ->where('role_id', $role->getKey())
            ->where('model_type', $user::class)
            ->exists())->toBeFalse();
    } finally {
        Relation::morphMap($originalMorphMap, false);
    }
});
