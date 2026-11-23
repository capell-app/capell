<?php

declare(strict_types=1);

use Capell\Admin\Support\Agent\AgentAdminToolInvocationService;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;

uses()->group('admin');

/** @param list<string> $permissions */
function agentReaderOnSite(Site $site, array $permissions): User
{
    $user = User::factory()->createOne();
    $user->assignedSiteIds = collect([(int) $site->getKey()]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

it('refuses to run a read tool the actor is not allowed to see in the tool list', function (string $tool, string $permission): void {
    $site = Site::factory()->createOne();
    $service = resolve(AgentAdminToolInvocationService::class);
    $withoutPermission = agentReaderOnSite($site, []);

    expect(collect($service->definitions($withoutPermission, (int) $site->getKey()))->pluck('name')->all())->not->toContain($tool)
        ->and(fn () => $service->invoke($tool, [], $withoutPermission, (int) $site->getKey()))
        ->toThrow(AuthorizationException::class);

    $withPermission = agentReaderOnSite($site, [$permission]);

    expect($service->invoke($tool, [], $withPermission, (int) $site->getKey())->ok)->toBeTrue();
})->with([
    'blueprints' => ['admin.blueprint.read', 'ViewAny:Blueprint'],
    'site readiness' => ['admin.site.agent_readiness.read', 'ViewAny:Page'],
]);
