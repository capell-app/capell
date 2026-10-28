<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildContentStatusAction;
use Capell\Admin\Actions\Dashboard\CanViewDashboardItemAction;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

it('evaluates each site with its own permissions and reflects revocation', function (): void {
    $first = Site::factory()->createOne();
    $second = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $actor = RealSiteScopedAdminResourceUser::query()->create(['name' => 'Editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($first->id);

    $firstRole = Role::findOrCreate('dashboard-first', 'web');
    $firstRole->givePermissionTo(Permission::findOrCreate('ViewAny:Page', 'web'));

    $actor->assignRoleForSite($first, 'dashboard-first');
    $registrar->setPermissionsTeamId($second->id);
    Role::findOrCreate('dashboard-second', 'web');
    $actor->assignRoleForSite($second, 'dashboard-second');
    $firstPage = Page::factory()->createOne(['site_id' => $first->id, 'visible_from' => PublishSentinel::draftValue()]);
    $secondPage = Page::factory()->createOne(['site_id' => $second->id, 'visible_from' => PublishSentinel::draftValue()]);
    Page::factory()->createOne(['site_id' => $foreign->id, 'visible_from' => PublishSentinel::draftValue()]);
    $registrar->setPermissionsTeamId($second->id);

    $counts = resolve(BuildContentStatusAction::class);
    expect($counts->handle($actor, null, null)['draft'])->toBe(1)
        ->and(resolve(CanViewDashboardItemAction::class)->handle($actor, $first->id, null, $firstPage->id))->toBeTrue()
        ->and(resolve(CanViewDashboardItemAction::class)->handle($actor, $second->id, null, $secondPage->id))->toBeFalse()
        ->and($counts->handle($actor, $foreign->id, null)['draft'])->toBe(0)
        ->and($registrar->getPermissionsTeamId())->toBe($second->id);

    $actor->removeRoleForSite($first, 'dashboard-first');
    expect($counts->handle($actor, null, null)['draft'])->toBe(0)
        ->and(resolve(CanViewDashboardItemAction::class)->handle($actor, $first->id, null, $firstPage->id))->toBeFalse();
});

it('restores the previous team and clears loaded permission relations on exception', function (): void {
    $site = Site::factory()->createOne();
    $actor = new RealSiteScopedAdminResourceUser;
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(8123);

    $actor->setRelation('roles', collect());
    $actor->setRelation('permissions', collect());
    try {
        resolve(DashboardSiteEvaluator::class)->run($actor, $site->id, function () use ($registrar, $site): void {
            expect($registrar->getPermissionsTeamId())->toBe($site->id);
            throw new RuntimeException('test');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('test');
    }

    expect($registrar->getPermissionsTeamId())->toBe(8123)
        ->and($actor->relationLoaded('roles'))->toBeFalse()
        ->and($actor->relationLoaded('permissions'))->toBeFalse();
});

it('keeps the active team for global administrators and restores it when teams are disabled', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $site = Site::factory()->createOne();
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(1234);

    $evaluator = resolve(DashboardSiteEvaluator::class);
    expect($evaluator->siteIds($actor, $site->id))->toBe([$site->id])
        ->and($evaluator->run($actor, $site->id, fn (): mixed => $registrar->getPermissionsTeamId()))->toBe(1234);
    $registrar->teams = false;
    expect($evaluator->run($actor, $site->id, fn (): mixed => $registrar->getPermissionsTeamId()))->toBe(1234)
        ->and($registrar->getPermissionsTeamId())->toBe(1234);
});
