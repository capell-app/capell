<?php

declare(strict_types=1);

use Capell\Admin\Actions\Sites\SyncSitePermissionsAction;
use Capell\Admin\Data\SitePermissions\SyncSitePermissionsData;
use Capell\Admin\Tests\Fixtures\Models\SitePermissionActionMorphMapTestUser;
use Capell\Admin\Tests\Fixtures\Models\SitePermissionActionTestUser;
use Capell\Admin\Tests\Fixtures\Models\SoftDeletingSitePermissionTestUser;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function makeSitePermissionActionTestUser(): SitePermissionActionTestUser
{
    $user = new SitePermissionActionTestUser;

    $user->forceFill([
        'name' => 'Site Permission User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    return $user;
}

function makeSitePermissionActionMorphMapTestUser(): SitePermissionActionMorphMapTestUser
{
    $user = new SitePermissionActionMorphMapTestUser;

    $user->forceFill([
        'name' => 'Site Permission Morph Map User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    return $user;
}

beforeEach(function (): void {
    config()->set('auth.providers.users.model', SitePermissionActionTestUser::class);

    Permission::findOrCreate('ManagePermissions:Site');
});

it('syncs submitted user role assignments for a site', function (): void {
    $site = Site::factory()->createOne();
    $admin = makeSitePermissionActionTestUser();
    $editor = makeSitePermissionActionTestUser();
    $reviewer = makeSitePermissionActionTestUser();
    $editorRole = Role::findOrCreate('editor');
    $adminRole = Role::findOrCreate('admin');

    $admin->givePermissionTo('ManagePermissions:Site');

    SyncSitePermissionsAction::run(
        actor: $admin,
        site: $site,
        input: SyncSitePermissionsData::fromArray([
            'assignments' => [
                ['user_id' => $editor->getKey(), 'role_ids' => [$editorRole->getKey()]],
                ['user_id' => $reviewer->getKey(), 'role_ids' => [$editorRole->getKey(), $adminRole->getKey()]],
            ],
        ]),
    );

    $freshEditor = expectPresent($editor->fresh());
    $freshReviewer = expectPresent($reviewer->fresh());

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->count())->toBe(3)
        ->and($freshEditor->getAssignedSiteIds()->all())->toBe([$site->getKey()])
        ->and($freshReviewer->getRolesForSite($site)->pluck('name')->all())
        ->toEqualCanonicalizing(['editor', 'admin']);
});

it('removes stale assignments for the managed site only', function (): void {
    $managedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $admin = makeSitePermissionActionTestUser();
    $editor = makeSitePermissionActionTestUser();
    $editorRole = Role::findOrCreate('editor');
    $adminRole = Role::findOrCreate('admin');

    $admin->givePermissionTo('ManagePermissions:Site');
    DB::table('model_has_roles')->insert([
        [
            'role_id' => $editorRole->getKey(),
            'model_type' => $editor->getMorphClass(),
            'model_id' => $editor->getKey(),
            'team_id' => $managedSite->getKey(),
        ],
        [
            'role_id' => $adminRole->getKey(),
            'model_type' => $editor->getMorphClass(),
            'model_id' => $editor->getKey(),
            'team_id' => $otherSite->getKey(),
        ],
    ]);

    $input = SyncSitePermissionsData::fromArray(['assignments' => []]);
    $review = resolve(SyncSitePermissionsAction::class)->review($admin, $managedSite, $input);

    SyncSitePermissionsAction::run(
        actor: $admin,
        site: $managedSite,
        input: $input,
        confirmationToken: $review->confirmationToken(),
    );

    $freshEditor = expectPresent($editor->fresh());

    expect($freshEditor->getAssignedSiteIds()->all())->toBe([$otherSite->getKey()])
        ->and($freshEditor->getRolesForSite($managedSite))->toHaveCount(0)
        ->and($freshEditor->getRolesForSite($otherSite)->pluck('name')->all())->toBe(['admin']);
});

it('does not delete global role assignments', function (): void {
    $site = Site::factory()->createOne();
    $admin = makeSitePermissionActionTestUser();
    $superAdmin = makeSitePermissionActionTestUser();
    $superAdminRole = Role::findOrCreate('super_admin');

    $admin->givePermissionTo('ManagePermissions:Site');
    $superAdmin->assignRole($superAdminRole);

    SyncSitePermissionsAction::run(
        actor: $admin,
        site: $site,
        input: SyncSitePermissionsData::fromArray(['assignments' => []]),
    );

    expect(DB::table('model_has_roles')
        ->whereNull('team_id')
        ->where('model_type', $superAdmin->getMorphClass())
        ->where('model_id', $superAdmin->getKey())
        ->exists())->toBeTrue();
});

it('rejects assigning the reserved super admin role within a site', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();
    $target = makeSitePermissionActionTestUser();
    $superAdminRole = Role::findOrCreate('super_admin');

    $actor->givePermissionTo('ManagePermissions:Site');

    SyncSitePermissionsAction::run(
        actor: $actor,
        site: $site,
        input: SyncSitePermissionsData::fromArray([
            'assignments' => [
                ['user_id' => $target->getKey(), 'role_ids' => [$superAdminRole->getKey()]],
            ],
        ]),
    );
})->throws(AuthorizationException::class);

it('uses the submitted users morph class when a morph map alias is active', function (): void {
    $previousMorphMap = Relation::morphMap();
    $previousRequiresMorphMap = Relation::requiresMorphMap();
    $previousUserModel = config('auth.providers.users.model');

    // Merge (the default) rather than replace: the application registers an
    // enforced morph map that includes Site, and creating a Site writes a
    // polymorphic activity-log subject. Replacing the map would drop Site and
    // throw "No morph map defined for [Site]" before the action even runs.
    Relation::enforceMorphMap([
        'admin-user' => SitePermissionActionMorphMapTestUser::class,
        'theme' => Theme::class,
    ]);
    config()->set('auth.providers.users.model', SitePermissionActionMorphMapTestUser::class);

    try {
        $site = Site::factory()->createOne();
        $admin = makeSitePermissionActionMorphMapTestUser();
        $editor = makeSitePermissionActionMorphMapTestUser();
        $editorRole = Role::findOrCreate('editor');

        $admin->givePermissionTo('ManagePermissions:Site');

        SyncSitePermissionsAction::run(
            actor: $admin,
            site: $site,
            input: SyncSitePermissionsData::fromArray([
                'assignments' => [
                    ['user_id' => $editor->getKey(), 'role_ids' => [$editorRole->getKey()]],
                ],
            ]),
        );

        expect(DB::table('model_has_roles')
            ->where('team_id', $site->getKey())
            ->where('model_type', 'admin-user')
            ->where('model_id', $editor->getKey())
            ->exists())->toBeTrue()
            ->and(DB::table('model_has_roles')
                ->where('team_id', $site->getKey())
                ->where('model_type', SitePermissionActionMorphMapTestUser::class)
                ->exists())->toBeFalse()
            ->and(expectPresent($editor->fresh())->getAssignedSiteIds()->all())->toBe([$site->getKey()]);
    } finally {
        Relation::morphMap($previousMorphMap, false);
        Relation::requireMorphMap($previousRequiresMorphMap);
        config()->set('auth.providers.users.model', $previousUserModel);
    }
});

it('rejects actors without manage site permissions', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();

    SyncSitePermissionsAction::run(
        actor: $actor,
        site: $site,
        input: SyncSitePermissionsData::fromArray(['assignments' => []]),
    );
})->throws(AuthorizationException::class);

it('rejects another sites role before replacing existing assignments', function (): void {
    $site = Site::factory()->createOne();
    $other = Site::factory()->createOne();
    $admin = makeSitePermissionActionTestUser();
    $editor = makeSitePermissionActionTestUser();
    $admin->givePermissionTo('ManagePermissions:Site');
    $role = Role::query()->create(['name' => 'foreign-editor', 'guard_name' => 'web', 'team_id' => $other->getKey()]);
    $input = SyncSitePermissionsData::fromArray(['assignments' => [
        ['user_id' => $editor->getKey(), 'role_ids' => [$role->getKey()]],
    ]]);

    expect(fn () => SyncSitePermissionsAction::run($admin, $site, $input))->toThrow(AuthorizationException::class)
        ->and(DB::table('model_has_roles')->where('team_id', $site->getKey())->exists())->toBeFalse();
});

it('refuses unreviewed removals and computes authorised named additions and revocations', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();
    $member = makeSitePermissionActionTestUser();
    $actor->givePermissionTo('ManagePermissions:Site');
    $oldRole = Role::findOrCreate('editor');
    $newRole = Role::findOrCreate('reviewer');
    DB::table('model_has_roles')->insert([
        'role_id' => $oldRole->getKey(), 'model_type' => $member->getMorphClass(),
        'model_id' => $member->getKey(), 'team_id' => $site->getKey(),
    ]);
    $input = SyncSitePermissionsData::fromArray(['assignments' => [
        ['user_id' => $member->getKey(), 'role_ids' => [$newRole->getKey()]],
    ]]);
    $review = resolve(SyncSitePermissionsAction::class)->review($actor, $site, $input);

    expect($review->additions)->toHaveCount(1)
        ->and($review->additions[0]->roleName)->toBe('reviewer')
        ->and($review->revocations)->toHaveCount(1)
        ->and($review->revocations[0]->userName)->toContain($member->email)
        ->and($review->revocations[0]->roleName)->toBe('editor')
        ->and($review->revocations[0]->losesSiteAccess)->toBeFalse();

    expect(fn () => SyncSitePermissionsAction::run($actor, $site, $input))->toThrow(ValidationException::class);
    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->pluck('role_id')->all())->toBe([$oldRole->getKey()]);
});

it('writes nothing for unchanged assignments including repeated saves', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();
    $member = makeSitePermissionActionTestUser();
    $actor->givePermissionTo('ManagePermissions:Site');
    $role = Role::findOrCreate('editor');
    $input = SyncSitePermissionsData::fromArray(['assignments' => [
        ['user_id' => $member->getKey(), 'role_ids' => [$role->getKey()]],
    ]]);
    SyncSitePermissionsAction::run($actor, $site, $input);

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        SyncSitePermissionsAction::run($actor, $site, $input);
        SyncSitePermissionsAction::run($actor, $site, $input);
        $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1);
        expect($writes)->toBeEmpty();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->count())->toBe(1);
});

it('rechecks the actors current authority rather than loaded roles when executing a review', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();
    $member = makeSitePermissionActionTestUser();
    $role = Role::findOrCreate('access-manager');
    $role->givePermissionTo('ManagePermissions:Site');

    $actor->assignRole($role);
    $actor->load('roles', 'permissions');

    $input = SyncSitePermissionsData::fromArray(['assignments' => [
        ['user_id' => $member->getKey(), 'role_ids' => [$role->getKey()]],
    ]]);
    $review = resolve(SyncSitePermissionsAction::class)->review($actor, $site, $input);
    DB::table('model_has_roles')->where('model_type', $actor->getMorphClass())
        ->where('model_id', $actor->getKey())->where('role_id', $role->getKey())->delete();

    expect(fn () => SyncSitePermissionsAction::run($actor, $site, $input, $review->confirmationToken()))
        ->toThrow(ValidationException::class, __('capell-admin::site_permissions.stale'));
    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->exists())->toBeFalse();
});

it('rejects changed users roles and submitted targets after review', function (string $change): void {
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();
    $member = makeSitePermissionActionTestUser();
    $actor->givePermissionTo('ManagePermissions:Site');
    $role = Role::findOrCreate('editor');
    $input = SyncSitePermissionsData::fromArray(['assignments' => [
        ['user_id' => $member->getKey(), 'role_ids' => [$role->getKey()]],
    ]]);
    $review = resolve(SyncSitePermissionsAction::class)->review($actor, $site, $input);

    match ($change) {
        'deleted user' => $member->delete(),
        'renamed user' => $member->update(['name' => 'Renamed member']),
        'renamed role' => $role->update(['name' => 'renamed-editor']),
        'changed guard' => $role->update(['guard_name' => 'api']),
        'changed target' => $input = SyncSitePermissionsData::fromArray(['assignments' => []]),
        default => throw new InvalidArgumentException('Unknown permission review change: ' . $change),
    };

    expect(fn () => SyncSitePermissionsAction::run($actor, $site, $input, $review->confirmationToken()))
        ->toThrow(ValidationException::class, __('capell-admin::site_permissions.stale'));
    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->exists())->toBeFalse();
})->with(['deleted user', 'renamed user', 'renamed role', 'changed guard', 'changed target']);

it('preserves assignments belonging to other model types when removing a reviewed user role', function (): void {
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();
    $member = makeSitePermissionActionTestUser();
    $actor->givePermissionTo('ManagePermissions:Site');
    $role = Role::findOrCreate('editor');
    foreach ([$member->getMorphClass(), 'another-model'] as $modelType) {
        DB::table('model_has_roles')->insert([
            'role_id' => $role->getKey(), 'model_type' => $modelType,
            'model_id' => $member->getKey(), 'team_id' => $site->getKey(),
        ]);
    }

    $input = SyncSitePermissionsData::fromArray(['assignments' => []]);
    $review = resolve(SyncSitePermissionsAction::class)->review($actor, $site, $input);
    SyncSitePermissionsAction::run($actor, $site, $input, $review->confirmationToken());

    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->pluck('model_type')->all())->toBe(['another-model']);
});

it('canonicalises retained legacy grants after a morph alias is enabled', function (bool $duplicate): void {
    $previousMorphMap = Relation::morphMap();
    $previousRequiresMorphMap = Relation::requiresMorphMap();
    config()->set('auth.providers.users.model', SitePermissionActionMorphMapTestUser::class);

    try {
        Relation::requireMorphMap(false);
        $site = Site::factory()->createOne();
        $otherSite = Site::factory()->createOne();
        $actor = makeSitePermissionActionMorphMapTestUser();
        $member = makeSitePermissionActionMorphMapTestUser();
        $role = Role::findOrCreate('editor');
        $legacyType = SitePermissionActionMorphMapTestUser::class;
        foreach ([$site->getKey(), $otherSite->getKey(), null] as $siteId) {
            DB::table('model_has_roles')->insert([
                'role_id' => $role->getKey(), 'model_type' => $legacyType,
                'model_id' => $member->getKey(), 'team_id' => $siteId,
            ]);
        }

        Relation::enforceMorphMap(['admin-user' => $legacyType]);
        $actor->givePermissionTo('ManagePermissions:Site');
        DB::table('model_has_roles')->insert([
            'role_id' => $role->getKey(), 'model_type' => 'unrelated-model',
            'model_id' => $member->getKey(), 'team_id' => $site->getKey(),
        ]);
        if ($duplicate) {
            DB::table('model_has_roles')->insert([
                'role_id' => $role->getKey(), 'model_type' => 'admin-user',
                'model_id' => $member->getKey(), 'team_id' => $site->getKey(),
            ]);
        }

        $preserved = DB::table('model_has_roles')->where('model_id', $member->getKey())
            ->where(fn ($query) => $query->whereNull('team_id')->orWhere('team_id', $otherSite->getKey())->orWhere('model_type', 'unrelated-model'))
            ->orderBy('team_id')->orderBy('model_type')->get()->all();
        $input = SyncSitePermissionsData::fromArray(['assignments' => [
            ['user_id' => $member->getKey(), 'role_ids' => [$role->getKey()]],
        ]]);
        $review = resolve(SyncSitePermissionsAction::class)->review($actor, $site, $input);
        expect($review->additions)->toBeEmpty()->and($review->revocations)->toBeEmpty();

        SyncSitePermissionsAction::run($actor, $site, $input, $review->confirmationToken());
        SyncSitePermissionsAction::run($actor, $site, $input);

        expect($member->getRolesForSite($site)->pluck('name')->all())->toBe(['editor'])
            ->and($member->getAssignedSiteIds()->all())->toBe([$site->getKey()])
            ->and(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $member->getKey())
                ->whereIn('model_type', [$legacyType, 'admin-user'])->pluck('model_type')->all())->toBe(['admin-user'])
            ->and(DB::table('model_has_roles')->where('model_id', $member->getKey())
                ->where(fn ($query) => $query->whereNull('team_id')->orWhere('team_id', $otherSite->getKey())->orWhere('model_type', 'unrelated-model'))
                ->orderBy('team_id')->orderBy('model_type')->get()->all())->toEqual($preserved);
    } finally {
        Relation::morphMap($previousMorphMap, false);
        Relation::requireMorphMap($previousRequiresMorphMap);
    }
})->with(['legacy only' => false, 'duplicate legacy and alias' => true]);

it('rejects a member becoming unavailable after a revocation was reviewed', function (): void {
    Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
    config()->set('auth.providers.users.model', SoftDeletingSitePermissionTestUser::class);
    $site = Site::factory()->createOne();
    $actor = makeSitePermissionActionTestUser();
    $actor->givePermissionTo('ManagePermissions:Site');

    $member = SoftDeletingSitePermissionTestUser::query()->forceCreate([
        'name' => 'Member', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password'),
    ]);
    $role = Role::findOrCreate('editor');
    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(), 'model_type' => $member->getMorphClass(),
        'model_id' => $member->getKey(), 'team_id' => $site->getKey(),
    ]);
    $input = SyncSitePermissionsData::fromArray(['assignments' => []]);
    $review = resolve(SyncSitePermissionsAction::class)->review($actor, $site, $input);
    $member->delete();

    expect(fn () => SyncSitePermissionsAction::run($actor, $site, $input, $review->confirmationToken()))
        ->toThrow(ValidationException::class, __('capell-admin::site_permissions.stale'));
    $retainedInput = SyncSitePermissionsData::fromArray(['assignments' => [
        ['user_id' => $member->getKey(), 'role_ids' => [$role->getKey()]],
    ]]);
    expect(fn () => SyncSitePermissionsAction::run($actor, $site, $retainedInput))
        ->toThrow(ValidationException::class, __('capell-admin::site_permissions.stale'));
    expect(DB::table('model_has_roles')->where('team_id', $site->getKey())->where('model_id', $member->getKey())->exists())->toBeTrue();
});
