<?php

declare(strict_types=1);

use Capell\Admin\Tests\Support\Models\TeamScopedPanelUser;
use Capell\Core\Actions\Install\CreateAdditionalInstallUsersAction;
use Capell\Core\Data\NewUserData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Tests\Fixtures\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function installStarterUsers(?Site $site = null): void
{
    CreateAdditionalInstallUsersAction::run([
        new NewUserData('Example Super Admin', 'super-admin@example.test', 'password123', 'super_admin'),
        new NewUserData('Example Editor', 'editor@example.test', 'password123', 'editor'),
    ], new NullProgressReporter, $site);
}

it('lets an install-created editor starter user into the admin panel', function (): void {
    config()->set('filament-shield.panel_user.enabled', true);
    $site = Site::factory()->createOne();

    installStarterUsers($site);

    $editor = User::query()->where('email', 'editor@example.test')->firstOrFail();
    $superAdmin = User::query()->where('email', 'super-admin@example.test')->firstOrFail();
    $panel = Filament::getPanel('admin');

    expect($editor->canAccessPanel($panel))->toBeTrue()
        ->and($editor->hasRole('panel_user'))->toBeTrue()
        ->and($superAdmin->canAccessPanel($panel))->toBeTrue()
        ->and($superAdmin->hasRole('panel_user'))->toBeFalse();
});

it('does not give starter users the panel user role when Shield has it disabled', function (): void {
    config()->set('filament-shield.panel_user.enabled', false);
    $site = Site::factory()->createOne();

    installStarterUsers($site);

    $editor = User::query()->where('email', 'editor@example.test')->firstOrFail();

    expect($editor->hasRole('panel_user'))->toBeFalse()
        ->and(Role::query()->where('name', 'panel_user')->exists())->toBeFalse();
});

it('keeps the panel user role global when roles are scoped to sites', function (): void {
    config(['permission.teams' => true, 'filament-shield.panel_user.enabled' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    $teamKey = resolve(PermissionRegistrar::class)->teamsKey;
    $site = Site::factory()->createOne();

    try {
        config(['auth.providers.users.model' => TeamScopedPanelUser::class]);
        installStarterUsers($site);

        $editor = TeamScopedPanelUser::query()->where('email', 'editor@example.test')->firstOrFail();
        $rows = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_id', $editor->getKey())
            ->join(config('permission.table_names.roles'), 'roles.id', '=', config('permission.table_names.model_has_roles') . '.role_id')
            ->pluck(config('permission.table_names.model_has_roles') . '.' . $teamKey, 'roles.name')
            ->all();

        expect(array_keys($rows))->toEqualCanonicalizing(['editor', 'panel_user'])
            ->and($rows['panel_user'])->toBeNull()
            ->and((int) $rows['editor'])->toBe($site->getKey());
    } finally {
        resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
        resolve(PermissionRegistrar::class)->teams = false;
        config(['permission.teams' => false]);
    }
});
