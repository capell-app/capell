<?php

declare(strict_types=1);

use Capell\Core\Support\Security\LockdownStore;
use Capell\Frontend\Http\Middleware\RefuseLivewireUpdatesWhileUnavailable;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Tests\Fixtures\Livewire\PublicCounterComponent;
use Capell\Frontend\Tests\FrontendTestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

beforeEach(function (): void {
    config()->set('capell.lockdown.file', storage_path('framework/testing/frontend-livewire-lockdown.json'));
    File::delete(config('capell.lockdown.file'));
    File::delete(resolve(MaintenanceManifestStore::class)->path());
    PublicCounterComponent::$increments = 0;
    Livewire::component('public-counter', PublicCounterComponent::class);
});

afterEach(function (): void {
    File::delete(config('capell.lockdown.file'));
    File::delete(resolve(MaintenanceManifestStore::class)->path());
});

/**
 * Renders the component on a route running the frontend middleware list (less the site resolver, which needs
 * a seeded site) and returns the update payload that clicks its button.
 *
 * @param  list<string>  $middleware
 * @return array<string, mixed>
 */
function renderPublicCounter(string $uri, array $middleware): array
{
    Route::middleware($middleware)->get($uri, static fn (): string => Livewire::mount('public-counter'));

    $html = test()->get($uri)->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $matches);
    $snapshot = html_entity_decode($matches[1] ?? throw new RuntimeException('Missing Livewire snapshot.'), ENT_QUOTES);

    return ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]]];
}

/** @return list<string> */
function publicFrontendMiddleware(): array
{
    return array_values(array_filter(
        resolve(FrontendRouteMiddlewareRegistry::class)->all(),
        static fn (string $middleware): bool => ! in_array($middleware, ['frontend.resolve', 'frontend.anonymous_cacheable_render', 'frontend.language_detect'], true),
    ));
}

/** @param  array<string, mixed>  $payload */
function postPublicUpdate(FrontendTestCase $test, array $payload): TestResponse
{
    return $test->postJson(resolve(HandleRequests::class)->getUpdateUri(), $payload, ['X-Livewire' => 'true']);
}

it('runs a public component update while the site is available', function (): void {
    $payload = renderPublicCounter('/public-counter', publicFrontendMiddleware());

    postPublicUpdate($this, $payload)->assertOk();

    expect(PublicCounterComponent::$increments)->toBe(1);
});

it('refuses a public component update from an anonymous visitor during lockdown and runs no action', function (): void {
    $payload = renderPublicCounter('/public-counter', publicFrontendMiddleware());

    resolve(LockdownStore::class)->activateFor(new GenericUser(['id' => 1]));
    Livewire::flushState();

    postPublicUpdate($this, $payload)->assertStatus(503);

    expect(PublicCounterComponent::$increments)->toBe(0);
});

it('refuses a public component update while the whole estate is in static maintenance', function (): void {
    $payload = renderPublicCounter('/public-counter', publicFrontendMiddleware());

    resolve(MaintenanceManifestStore::class)->write(['version' => 2, 'global_active' => true, 'sites' => []]);
    Livewire::flushState();

    postPublicUpdate($this, $payload)->assertStatus(503);

    expect(PublicCounterComponent::$increments)->toBe(0);
});

it('registers the update guard on every frontend route and as persistent Livewire middleware', function (): void {
    expect(resolve(FrontendRouteMiddlewareRegistry::class)->all())->toContain(RefuseLivewireUpdatesWhileUnavailable::class)
        ->and(resolve(PersistentMiddleware::class)->getPersistentMiddleware())
        ->toContain(RefuseLivewireUpdatesWhileUnavailable::class);
});

it('leaves an update from a route outside the frontend group alone during lockdown', function (): void {
    $payload = renderPublicCounter('/non-frontend-counter', ['web']);

    resolve(LockdownStore::class)->activateFor(new GenericUser(['id' => 1]));
    Livewire::flushState();

    postPublicUpdate($this, $payload)->assertOk();

    expect(PublicCounterComponent::$increments)->toBe(1);
});
