<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Filament\Resources\Users\Tables\UsersTable;
use Capell\Core\Database\Factories\RoleFactory;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertModelMissing;

use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can list users', function (): void {
    /** @var class-string<User> $model */
    /** @var class-string $model */
    $model = config('auth.providers.users.model');

    $totalUsers = $model::query()->count();

    /** @var EloquentCollection<int, User> $users */
    $users = UserFactory::new()->count(5)->create();

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertCountTableRecords($totalUsers + 5)
        ->assertCanSeeTableRecords($users);
});

it('can search users', function (): void {
    /** @var class-string<User> $model */
    /** @var class-string $model */
    $model = config('auth.providers.users.model');

    $totalUsers = $model::query()->count();

    /** @var EloquentCollection<int, User> $users */
    $users = UserFactory::new()
        ->sequence(fn (Sequence $sequence): array => ['name' => sprintf('User(%d)', $sequence->index)])
        ->count(3)
        ->create();

    $name = $users->random()->name;

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertCountTableRecords($totalUsers + 3)
        ->searchTable($name)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords($users->where('name', $name))
        ->assertCanNotSeeTableRecords($users->where('name', '!=', $name));
});

it('can sort users', function (): void {
    /** @var class-string<User> $model */
    /** @var class-string $model */
    $model = config('auth.providers.users.model');

    $totalUsers = $model::query()->count();

    /** @var EloquentCollection<int, User> $users */
    $users = UserFactory::new()->count(5)->create();
    $usersInDatabaseOrder = $model::query()
        ->whereKey($users->modelKeys())
        ->orderBy('name')
        ->get();

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertCountTableRecords($totalUsers + 5)
        ->sortTable('name')
        ->assertCanSeeTableRecords($usersInDatabaseOrder, inOrder: true);
});

it('can group delete users', function (): void {
    $user = UserFactory::new()->createOne();
    /** @var EloquentCollection<int, User> $users */
    $users = UserFactory::new()->count(5)->create();

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->selectTableRecords($users)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    assertModelExists($user);

    foreach ($users as $deletedUser) {
        assertModelMissing($deletedUser, ['id' => $deletedUser->id]);
    }
});

it('can filter users by role', function (): void {
    $adminRole = (new RoleFactory)->create(['name' => 'Admin']);
    $editorRole = (new RoleFactory)->create(['name' => 'Editor']);

    $adminUser = UserFactory::new()->createOne();
    $adminUser->roles()->attach($adminRole);

    $editorUser = UserFactory::new()->createOne();
    $editorUser->roles()->attach($editorRole);

    $otherUser = UserFactory::new()->createOne();

    Livewire::test(ListUsers::class)
        ->filterTable('roles', $adminRole->id)
        ->assertCanSeeTableRecords([$adminUser])
        ->assertCanNotSeeTableRecords([$editorUser, $otherUser]);

    Livewire::test(ListUsers::class)
        ->filterTable('roles', $editorRole->id)
        ->assertCanSeeTableRecords([$editorUser])
        ->assertCanNotSeeTableRecords([$adminUser, $otherUser]);
});

it('can select any editable role to edit from a user row', function (): void {
    $firstRole = (new RoleFactory)->create(['name' => 'First role']);
    $secondRole = (new RoleFactory)->create(['name' => 'Second role']);
    $user = UserFactory::new()->createOne();
    $user->roles()->attach([$firstRole->id, $secondRole->id]);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->mountTableAction('edit-role', $user)
        ->assertSchemaStateSet([
            'role_id' => null,
        ])
        ->setTableActionData([
            'role_id' => $secondRole->id,
        ])
        ->callMountedTableAction()
        ->assertRedirect(RoleResource::getUrl('edit', ['record' => $secondRole]));
});

it('can filter users by verified status', function (): void {
    $verifiedUser = UserFactory::new()->createOne(['email_verified_at' => now()]);
    $unverifiedUser = UserFactory::new()->createOne(['email_verified_at' => null]);

    Livewire::test(ListUsers::class)
        ->filterTable('email_verified_at', true)
        ->assertCanSeeTableRecords([$verifiedUser])
        ->assertCanNotSeeTableRecords([$unverifiedUser]);

    Livewire::test(ListUsers::class)
        ->filterTable('email_verified_at', false)
        ->assertCanSeeTableRecords([$unverifiedUser])
        ->assertCanNotSeeTableRecords([$verifiedUser]);
});

it('distinguishes user assignments from shared role permission editing', function (): void {
    $role = (new RoleFactory)->create(['name' => 'Shared editor']);
    $user = UserFactory::new()->createOne();
    $user->roles()->attach($role);

    Livewire::test(ListUsers::class)
        ->assertTableActionExists('edit', fn ($action): bool => $action->getLabel() === __('capell-admin::button.edit_user'))
        ->assertTableActionExists('edit-role', fn ($action): bool => $action->getLabel() === __('capell-admin::button.edit_role_permissions'))
        ->assertTableActionExists('edit-role', fn ($action): bool => $action->getModalDescription() === __('capell-admin::generic.shared_role_permissions_notice'));

    $options = new ReflectionMethod(UsersTable::class, 'editableRoleOptions')->invoke(null, $user->refresh()->load('roles'));
    expect($options[$role->id])->toContain($role->name)->toContain(__('capell-admin::generic.role_scope_global'));
});

it('batches role scope queries when rendering users sharing a role', function (): void {
    $role = (new RoleFactory)->create(['name' => 'Shared visible role']);
    $users = UserFactory::new()->createMany(25);
    foreach ($users as $user) {
        $user->roles()->attach($role);
    }

    $page = Livewire::test(ListUsers::class)->assertSuccessful();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $page->set('tableRecordsPerPage', 50)->assertSuccessful()->assertCanSeeTableRecords($users);
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    $scopeQueries = $queries->filter(fn (array $query): bool => preg_match('/^select ["`]?id["`]? from ["`]?roles["`]?/', $query['query']) === 1 && ! str_contains($query['query'], 'limit 1'));
    expect($scopeQueries)->toHaveCount(1);
    foreach ($page->instance()->getTableRecords() as $record) {
        if ($users->contains('id', $record->getKey())) {
            $page->assertTableActionVisible('edit-role', $record);
        }
    }
});

it('rechecks role scope and policy at submission after row visibility was cached', function (string $restriction): void {
    test()->actingAsUser();
    $policy = new class
    {
        public bool $blocked = false;
    };
    Gate::before(fn (User $actor, string $ability, array $arguments): bool => ! ($policy->blocked && $ability === 'update' && ($arguments[0] ?? null) instanceof Role));
    $role = (new RoleFactory)->create(['name' => 'Previously editable role']);
    $user = test()->authenticatedUser();
    $user->roles()->attach($role);
    $page = Livewire::test(ListUsers::class)->assertSuccessful();
    $record = $page->instance()->getTableRecords()->firstWhere('id', $user->id);
    expect($record)->toBeInstanceOf(User::class);
    $action = $page->instance()->getTable()->getAction('edit-role')->record($record);
    expect($action->isVisible())->toBeTrue();

    $originalScopes = Role::getAllGlobalScopes();
    try {
        if ($restriction === 'scope') {
            Role::addGlobalScope('submission-role-scope', fn (Builder $query) => $query->whereKeyNot($role->id));
        } else {
            $policy->blocked = true;
        }

        try {
            $action->call(['data' => ['role_id' => $role->id]]);
            $this->fail('A role that is no longer editable must not be submitted.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(403);
        }
    } finally {
        Role::setAllGlobalScopes($originalScopes);
    }
})->with(['scope', 'policy']);
