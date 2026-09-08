<?php

declare(strict_types=1);

use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Spatie\Permission\PermissionRegistrar;

function runSiteScopeMiddleware(Request $request): void
{
    (new SetSitePermissionScope)->handle($request, fn (): Response => new Response('ok'));
}

function currentTeamId(): int|string|null
{
    return resolve(PermissionRegistrar::class)->getPermissionsTeamId();
}

beforeEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

afterEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('ignores ?site_id when the user has no assigned sites', function (): void {
    $otherSite = Site::factory()->createOne();

    $user = User::factory()->createOne();

    $request = Request::create('/admin/something?site_id=' . $otherSite->id);
    $request->setUserResolver(fn () => $user);

    runSiteScopeMiddleware($request);

    expect(currentTeamId())->toBeNull();
});

it('ignores ?site_id when the request is unauthenticated', function (): void {
    $otherSite = Site::factory()->createOne();

    $request = Request::create('/admin/something?site_id=' . $otherSite->id);

    runSiteScopeMiddleware($request);

    expect(currentTeamId())->toBeNull();
});

it('uses the site_id of a route-bound record (record takes precedence over query param)', function (): void {
    $recordSite = Site::factory()->createOne();
    $querySite = Site::factory()->createOne();

    $user = User::factory()->createOne();

    $request = Request::create('/admin/sites/' . $recordSite->id . '?site_id=' . $querySite->id);
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(function () use ($recordSite): Route {
        $route = new Route(['GET'], '/admin/sites/{site}', fn (): null => null);
        $route->bind(request());
        $route->setParameter('site', $recordSite);

        return $route;
    });

    (new SetSitePermissionScope)->handle($request, function () use ($recordSite): Response {
        expect(currentTeamId())->toBe($recordSite->id);

        return new Response('ok');
    });

    expect(currentTeamId())->toBeNull();
});

it('clears stale scope during an unresolved request and restores it on exit', function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(987);
    $request = Request::create('/admin/something');
    $session = new Store('scope-test', new ArraySessionHandler(120));
    $request->setLaravelSession($session);

    (new SetSitePermissionScope)->handle($request, function (): Response {
        expect(currentTeamId())->toBeNull();

        return new Response('ok');
    });

    expect(currentTeamId())->toBe(987);
});
