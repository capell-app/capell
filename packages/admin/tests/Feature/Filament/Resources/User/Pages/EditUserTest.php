<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Users\Pages\EditUser;
use Capell\Admin\Models\AdminNotificationSubscription;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Core\Models\Language;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Fixtures\Models\UserWithoutMedia;
use Capell\Tests\Fixtures\Policies\UserPolicy;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)->group('user');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can retrieve data', function (): void {
    $user = UserFactory::new()->createOne();

    Livewire::test(EditUser::class, [
        'record' => $user->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::form.user_identity'))
        ->assertSee(__('capell-admin::generic.user_identity_description'))
        ->assertSee(__('capell-admin::form.user_credentials'))
        ->assertSee(__('capell-admin::generic.user_credentials_description'))
        ->assertSee(__('capell-admin::generic.user_password_confirmation_info'))
        ->assertSee(__('capell-admin::generic.user_profile_description'))
        ->assertSchemaStateSet([
            'name' => $user->name,
        ]);
});

it('can retrieve data for host user models without media support', function (): void {
    config()->set('auth.providers.users.model', UserWithoutMedia::class);
    Relation::morphMap(['user_without_media' => UserWithoutMedia::class]);

    $user = UserWithoutMedia::query()->create([
        'name' => 'Plain Host User',
        'email' => 'plain-host-user@example.test',
        'password' => 'password',
    ]);

    Livewire::test(EditUser::class, [
        'record' => $user->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSchemaStateSet([
            'name' => 'Plain Host User',
        ]);
});

it('edits a user', function (): void {
    $user = UserFactory::new()->createOne(['name' => 'Old Name']);

    $payload = [
        'name' => 'Updated Name',
        'email' => $user->email,
        'roles' => [],
    ];

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm($payload)
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Updated Name',
    ]);
});

it('edits a user preferred admin language', function (): void {
    $language = Language::factory()->english()->create(['status' => true]);
    $user = UserFactory::new()->createOne(['name' => 'Locale User']);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm([
            'name' => 'Locale User',
            'email' => $user->email,
            'preferred_admin_language_id' => $language->getKey(),
            'roles' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('users', [
        'id' => $user->id,
        'preferred_admin_language_id' => $language->getKey(),
    ]);
});

it('persists notification preference overrides from the user edit form', function (): void {
    $user = UserFactory::new()->createOne(['name' => 'Operations User']);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm([
            'name' => 'Operations User',
            'email' => $user->email,
            'roles' => [],
            'admin_notification_subscriptions' => ['package_operations'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $subscription = AdminNotificationSubscription::query()
        ->where('user_type', $user::class)
        ->where('user_id', $user->getKey())
        ->where('group_key', 'package_operations')
        ->firstOrFail();

    expect($subscription->subscribed)->toBeTrue();
});

it('validates preferred admin language before saving the user form', function (): void {
    $disabledLanguage = Language::factory()->english()->create(['status' => false]);
    $user = UserFactory::new()->createOne(['name' => 'Original Name']);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm([
            'name' => 'Changed Name',
            'email' => $user->email,
            'preferred_admin_language_id' => $disabledLanguage->getKey(),
            'roles' => [],
        ])
        ->call('save')
        ->assertHasFormErrors(['preferred_admin_language_id']);

    assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Original Name',
        'preferred_admin_language_id' => null,
    ]);
});

it('shows validation errors for missing required fields on edit', function (): void {
    $user = UserFactory::new()->createOne();

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm([
            'name' => '',
            'email' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['name', 'email']);
});

it('shows validation error for invalid email on edit', function (): void {
    $user = UserFactory::new()->createOne();

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm([
            'name' => 'Valid Name',
            'email' => 'not-an-email',
        ])
        ->call('save')
        ->assertHasFormErrors(['email']);
});

it('does not let non super admins assign roles through the user form', function (): void {
    Gate::policy(User::class, UserPolicy::class);

    foreach (['view_user', 'view_any_user', 'update_user'] as $permissionName) {
        Permission::findOrCreate($permissionName);
    }

    $actor = test()->createUser();
    $actor->givePermissionTo(['view_user', 'view_any_user', 'update_user']);

    $superAdminRole = Role::findOrCreate('super_admin');
    $targetUser = UserFactory::new()->createOne(['name' => 'Target User']);

    test()->actingAs($actor);

    Livewire::test(EditUser::class, ['record' => $targetUser->getKey()])
        ->fillForm([
            'name' => 'Updated Target User',
            'email' => $targetUser->email,
            'roles' => [$superAdminRole->getKey()],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(expectPresent($targetUser->fresh())->hasRole('super_admin'))->toBeFalse();
});
