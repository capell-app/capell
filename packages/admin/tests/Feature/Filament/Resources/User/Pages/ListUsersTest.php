<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Core\Database\Factories\RoleFactory;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Livewire\Livewire;

use function Pest\Laravel\assertModelExists;
use function Pest\Laravel\assertModelMissing;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('can list users', function (): void {
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

test('can search users', function (): void {
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

test('can sort users', function (): void {
    /** @var class-string<User> $model */
    /** @var class-string $model */
    $model = config('auth.providers.users.model');

    $totalUsers = $model::query()->count();

    /** @var EloquentCollection<int, User> $users */
    $users = UserFactory::new()->count(5)->create();

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertCountTableRecords($totalUsers + 5)
        ->sortTable('name')
        ->assertCanSeeTableRecords($users->sortBy('name'), inOrder: true);
});

test('can group delete users', function (): void {
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

test('can filter users by role', function (): void {
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

test('can select any editable role to edit from a user row', function (): void {
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

test('can filter users by verified status', function (): void {
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
