<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Tests\Fixtures\Models\ManageSitePermissionsActionTestUser;
use Capell\Admin\Tests\Fixtures\Models\SoftDeletingSitePermissionTestUser;
use Capell\Core\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function makeManageSitePermissionsActionTestUser(): ManageSitePermissionsActionTestUser
{
    $user = new ManageSitePermissionsActionTestUser;

    $user->forceFill([
        'name' => 'Manage Site Permissions User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    return $user;
}

function assignRoleForSiteInDatabase(ManageSitePermissionsActionTestUser $user, Site $site, RoleContract $role): void
{
    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $site->getKey(),
    ]);
}

beforeEach(function (): void {
    config()->set('auth.providers.users.model', ManageSitePermissionsActionTestUser::class);

    Permission::findOrCreate(CapellPermission::ManageSitePermissions->name());
    Permission::findOrCreate('Update:Site');
    Role::findOrCreate('editor');
    Role::findOrCreate('super_admin');
});

it('shows the manage permissions action to users with the custom permission', function (): void {
    $site = Site::factory()->createOne();
    $siteUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $siteUser->givePermissionTo([
        CapellPermission::ManageSitePermissions->name(),
        'Update:Site',
    ]);
    assignRoleForSiteInDatabase($siteUser, $site, $editorRole);

    test()->actingAs($siteUser);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('manage_site_permissions');
});

it('hides the manage permissions action from users without permission', function (): void {
    $site = Site::factory()->createOne();
    $siteUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $siteUser->givePermissionTo('Update:Site');
    assignRoleForSiteInDatabase($siteUser, $site, $editorRole);

    test()->actingAs($siteUser);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('manage_site_permissions');
});

it('syncs site permissions from the edit site action', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $assignedUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);
    $actor->assignRole('super_admin');

    test()->actingAs($actor);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->mountAction('manage_site_permissions')
        ->fillForm([
            'assignments' => [
                [
                    'user_id' => $assignedUser->getKey(),
                    'role_ids' => [$editorRole->getKey()],
                ],
            ],
        ])
        ->goToNextWizardStep()
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DB::table('model_has_roles')
        ->where('model_type', $assignedUser->getMorphClass())
        ->where('model_id', $assignedUser->getKey())
        ->where('role_id', $editorRole->getKey())
        ->where('team_id', $site->getKey())
        ->exists())->toBeTrue();
});

it('hydrates existing site permissions into the modal form', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $assignedUser = makeManageSitePermissionsActionTestUser();
    /** @var Role $editorRole */
    $editorRole = Role::findOrCreate('editor');

    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);
    $actor->assignRole('super_admin');
    assignRoleForSiteInDatabase($assignedUser, $site, $editorRole);

    test()->actingAs($actor);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->assertSuccessful()
        ->mountAction('manage_site_permissions')
        ->assertActionDataSet(function (array $state) use ($assignedUser, $editorRole): array {
            /** @var list<array{user_id: mixed, role_ids?: list<mixed>}> $rawAssignments */
            $rawAssignments = $state['assignments'] ?? [];

            $assignments = collect($rawAssignments)
                ->map(fn (array $assignment): array => [
                    'user_id' => (int) $assignment['user_id'],
                    'role_ids' => collect($assignment['role_ids'] ?? [])
                        ->map(fn (mixed $roleId): int => (int) $roleId)
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();

            expect($assignments)->toBe([
                [
                    'user_id' => $assignedUser->getKey(),
                    'role_ids' => [$editorRole->getKey()],
                ],
            ]);

            return [];
        });
});

it('requires named review and confirmation before revoking the actors own site access', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);

    $role = Role::findOrCreate('editor');
    assignRoleForSiteInDatabase($actor, $site, $role);
    test()->actingAs($actor);

    $component = Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->mountAction('manage_site_permissions')
        ->fillForm(['assignments' => []])
        ->goToNextWizardStep()
        ->assertHasNoActionErrors();

    // Filament returns mounted modal updates as partials, not replacement page HTML.
    expect($component->effects['partials']['action-modals.0'])->toContain(
        e($actor->name),
        e($actor->email),
        e($role->name),
        e(__('capell-admin::site_permissions.own_access_lost')),
    );

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $actor->getKey())->exists())->toBeTrue();

    $component->callMountedAction()->assertHasActionErrors(['confirm_revocations' => 'accepted']);

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $actor->getKey())->exists())->toBeTrue();

    $component->fillForm(['confirm_revocations' => true])->callMountedAction()->assertHasNoActionErrors();

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $actor->getKey())->exists())->toBeFalse();
});

it('rejects a stale reviewed submission without overwriting another administrators assignments', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $member = makeManageSitePermissionsActionTestUser();
    $newMember = makeManageSitePermissionsActionTestUser();
    $actor->assignRole('super_admin');
    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);

    $role = Role::findOrCreate('editor');
    assignRoleForSiteInDatabase($member, $site, $role);
    test()->actingAs($actor);

    $component = Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->mountAction('manage_site_permissions')
        ->fillForm(['assignments' => []])
        ->goToNextWizardStep()
        ->fillForm(['confirm_revocations' => true]);

    assignRoleForSiteInDatabase($newMember, $site, $role);

    $component->callMountedAction()
        ->assertHasActionErrors(['assignments' => __('capell-admin::site_permissions.stale')]);

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->orderBy('model_id')->pluck('model_id')->all())
        ->toEqualCanonicalizing([$member->getKey(), $newMember->getKey()]);
});

it('rejects membership changes made while the assignment form was open', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $member = makeManageSitePermissionsActionTestUser();
    $actor->assignRole('super_admin');
    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);

    test()->actingAs($actor);

    $component = Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->mountAction('manage_site_permissions');

    assignRoleForSiteInDatabase($member, $site, Role::findOrCreate('editor'));

    $component->goToNextWizardStep()
        ->assertHasActionErrors(['assignments' => __('capell-admin::site_permissions.stale')]);

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->count())->toBe(1);
});

it('keeps existing pivots untouched when the same assignments are saved repeatedly through the modal', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $member = makeManageSitePermissionsActionTestUser();
    $actor->assignRole('super_admin');
    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);
    assignRoleForSiteInDatabase($member, $site, Role::findOrCreate('editor'));
    test()->actingAs($actor);
    $component = Livewire::test(EditSite::class, ['record' => $site->getRouteKey()]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        for ($save = 0; $save < 2; $save++) {
            $component->mountAction('manage_site_permissions')
                ->goToNextWizardStep()
                ->callMountedAction()
                ->assertHasNoActionErrors();
        }

        $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'model_has_roles')
            && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1);
        expect($writes)->toBeEmpty();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->count())->toBe(1);
});

it('reviews and confirms cleanup of a member soft deleted before the modal opens', function (): void {
    Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
    config()->set('auth.providers.users.model', SoftDeletingSitePermissionTestUser::class);
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $actor = makeManageSitePermissionsActionTestUser();
    $newMember = makeManageSitePermissionsActionTestUser();
    $actor->assignRole('super_admin');
    $actor->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);

    $member = SoftDeletingSitePermissionTestUser::query()->forceCreate([
        'name' => 'Deleted member', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password'),
    ]);
    $role = Role::findOrCreate('editor');
    foreach ([$site->getKey(), $otherSite->getKey(), null] as $siteId) {
        DB::table('model_has_roles')->insert([
            'role_id' => $role->getKey(), 'model_type' => $member->getMorphClass(),
            'model_id' => $member->getKey(), 'team_id' => $siteId,
        ]);
    }

    $member->delete();
    $preserved = DB::table('model_has_roles')->where('model_id', $member->getKey())
        ->where(fn ($query) => $query->whereNull('team_id')->orWhere('team_id', $otherSite->getKey()))
        ->orderBy('team_id')->get()->all();
    test()->actingAs($actor);

    $component = Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->mountAction('manage_site_permissions')
        ->assertActionDataSet(['assignments' => []])
        ->fillForm(['assignments' => [
            ['user_id' => $newMember->getKey(), 'role_ids' => [$role->getKey()]],
        ]])
        ->goToNextWizardStep()
        ->assertHasNoActionErrors();

    expect($component->effects['partials']['action-modals.0'])->toContain(
        e(__('capell-admin::site_permissions.unavailable_member', ['id' => $member->getKey()])),
        e($role->name),
    );
    $component->callMountedAction()->assertHasActionErrors(['confirm_revocations' => 'accepted']);
    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $member->getKey())->exists())->toBeTrue();
    $component->fillForm(['confirm_revocations' => true])->callMountedAction()->assertHasNoActionErrors();

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $member->getKey())->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $newMember->getKey())->where('role_id', $role->getKey())->exists())->toBeTrue()
        ->and(DB::table('model_has_roles')->where('model_id', $member->getKey())->orderBy('team_id')->get()->all())->toEqual($preserved);
});

it('offers a site manager only people who can already be reached from their site and refuses forged ids', function (): void {
    $siteA = Site::factory()->createOne();
    $siteB = Site::factory()->createOne();
    $editor = Role::findOrCreate('editor');

    $manager = makeManageSitePermissionsActionTestUser();
    $manager->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);
    assignRoleForSiteInDatabase($manager, $siteA, $editor);

    $member = makeManageSitePermissionsActionTestUser();
    assignRoleForSiteInDatabase($member, $siteA, $editor);

    $foreign = makeManageSitePermissionsActionTestUser();
    $foreign->forceFill(['name' => 'Foreign Person', 'email' => 'foreign@other-site.test'])->save();
    assignRoleForSiteInDatabase($foreign, $siteB, $editor);

    $globalAdmin = makeManageSitePermissionsActionTestUser();
    $globalAdmin->forceFill(['name' => 'Global Admin', 'email' => 'global@everywhere.test'])->save();
    $globalAdmin->assignRole('super_admin');

    $globalRoleHolder = makeManageSitePermissionsActionTestUser();
    $globalRoleHolder->forceFill(['name' => 'Global Role Holder', 'email' => 'holder@everywhere.test'])->save();
    DB::table('model_has_roles')->insert([
        'role_id' => $editor->getKey(), 'model_type' => $globalRoleHolder->getMorphClass(),
        'model_id' => $globalRoleHolder->getKey(), 'team_id' => null,
    ]);
    assignRoleForSiteInDatabase($globalRoleHolder, $siteA, $editor);

    $unrelated = makeManageSitePermissionsActionTestUser();
    $unrelated->forceFill(['name' => 'Unrelated Person', 'email' => 'unrelated@nowhere.test'])->save();

    test()->actingAs($manager);

    $component = Livewire::test(EditSite::class, ['record' => $siteA->getRouteKey()])
        ->mountAction('manage_site_permissions');

    $html = json_encode($component->effects['partials'] ?? []) . $component->html();
    expect($html)->not->toContain('foreign@other-site.test')
        ->and($html)->not->toContain('global@everywhere.test')
        ->and($html)->not->toContain('unrelated@nowhere.test');

    foreach ([$foreign, $globalAdmin, $unrelated] as $target) {
        Livewire::test(EditSite::class, ['record' => $siteA->getRouteKey()])
            ->mountAction('manage_site_permissions')
            ->fillForm(['assignments' => [
                ['user_id' => $member->getKey(), 'role_ids' => [$editor->getKey()]],
                ['user_id' => $target->getKey(), 'role_ids' => [$editor->getKey()]],
            ]])
            ->goToNextWizardStep()
            ->callMountedAction();

        expect(DB::table('model_has_roles')->where('team_id', $siteA->getKey())->where('model_id', $target->getKey())->exists())->toBeFalse();
    }
});

it('still lets a site manager change the roles of an existing member of their site', function (): void {
    $site = Site::factory()->createOne();
    $editor = Role::findOrCreate('editor');
    $reviewer = Role::findOrCreate('reviewer');

    $manager = makeManageSitePermissionsActionTestUser();
    $manager->givePermissionTo([CapellPermission::ManageSitePermissions->name(), 'Update:Site']);
    assignRoleForSiteInDatabase($manager, $site, $editor);

    $member = makeManageSitePermissionsActionTestUser();
    assignRoleForSiteInDatabase($member, $site, $editor);

    test()->actingAs($manager);

    Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
        ->mountAction('manage_site_permissions')
        ->fillForm(['assignments' => [
            ['user_id' => $manager->getKey(), 'role_ids' => [$editor->getKey()]],
            ['user_id' => $member->getKey(), 'role_ids' => [$reviewer->getKey()]],
        ]])
        ->goToNextWizardStep()
        ->fillForm(['confirm_revocations' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $member->getKey())->pluck('role_id')->all())
        ->toBe([$reviewer->getKey()]);
});
