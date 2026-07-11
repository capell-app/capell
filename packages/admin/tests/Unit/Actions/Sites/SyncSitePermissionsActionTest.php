<?php

declare(strict_types=1);

use Capell\Admin\Actions\Sites\SyncSitePermissionsAction;
use Capell\Admin\Data\SitePermissions\SyncSitePermissionsData;
use Capell\Admin\Tests\Fixtures\Models\SitePermissionActionMorphMapTestUser;
use Capell\Admin\Tests\Fixtures\Models\SitePermissionActionTestUser;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
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

    SyncSitePermissionsAction::run(
        actor: $admin,
        site: $managedSite,
        input: SyncSitePermissionsData::fromArray(['assignments' => []]),
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
