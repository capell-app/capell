<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Middleware\EnsureInstalledPanelAvailable;
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeCounterComponent;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeWireBlockMiddleware;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use RuntimeException;

/** A Livewire update is held to the route that rendered the component, reconciled as that route stands now. */
final class InstalledPanelRuntimeLivewireReplayTest extends AdminTestCase
{
    private const string URI = '/admin/runtime-replay';

    public function test_a_replay_runs_panel_middleware_gained_after_the_render(): void
    {
        $payload = $this->render();
        $this->replay($payload)->assertOk();

        // Livewire's replay honours middleware that aborts or redirects, not a returned response.
        Filament::getPanel('admin')->middleware([RuntimeWireBlockMiddleware::class]);
        Livewire::flushState();

        $this->replay($payload)->assertForbidden();
    }

    public function test_a_replay_of_a_refused_route_is_refused_even_when_the_route_excludes_the_availability_guard(): void
    {
        $payload = $this->render();
        $this->replay($payload)->assertOk();

        $route = Route::getRoutes()->match(request()->create(self::URI));
        $this->assertInstanceOf(RoutingRoute::class, $route);
        $route->withoutMiddleware([EnsureInstalledPanelAvailable::class . ':admin']);
        Livewire::flushState();

        $this->replay($payload)->assertStatus(503);
    }

    /** A snapshot whose rendering route no longer exists cannot have its middleware replayed, so it is rejected. */
    public function test_a_replay_whose_rendering_route_no_longer_matches_is_rejected(): void
    {
        $payload = $this->render();
        $this->replay($payload)->assertOk();

        $this->withoutRenderingRoute();
        Livewire::flushState();

        $this->replay($payload)->assertStatus(419);
    }

    /**
     * Once the rendering admin route is gone, a public catch-all answering the same path is a different
     * route, so the snapshot is not replayed through it without the panel's middleware.
     */
    public function test_a_replay_is_rejected_when_a_different_route_now_answers_its_path(): void
    {
        $payload = $this->render();
        $this->replay($payload)->assertOk();

        $this->withoutRenderingRoute();
        Route::get('{path}', static fn (): string => 'public page')->where('path', '.*')->middleware('web');
        Livewire::flushState();

        $this->replay($payload)->assertStatus(419);
    }

    /** A panel snapshot that does not carry its rendering route cannot be tied to it, so it is rejected. */
    public function test_a_panel_snapshot_without_its_rendering_route_is_rejected(): void
    {
        $payload = $this->withoutRouteIdentity($this->render());

        $this->replay($payload)->assertStatus(419);
    }

    /**
     * A snapshot without its signed rendering route cannot prove which route rendered it, so it is
     * rejected even where a public route answers its path now.
     */
    public function test_a_snapshot_without_its_rendering_route_is_rejected_on_a_public_route(): void
    {
        $payload = $this->withoutRouteIdentity($this->render('/runtime-public-replay', ['web']));

        $this->replay($payload)->assertStatus(419);
    }

    /** An admin route outside every panel path, once removed, cannot replay an unsigned snapshot through a public catch-all. */
    public function test_an_unsigned_snapshot_of_a_removed_admin_route_outside_the_panel_path_is_rejected(): void
    {
        $payload = $this->withoutRouteIdentity($this->render('/runtime-outside-admin', ['web'], adminCode: true));
        $this->replayRoutesWithout('/runtime-outside-admin');
        Route::get('{path}', static fn (): string => 'public page')->where('path', '.*')->middleware('web');
        Livewire::flushState();

        $this->replay($payload)->assertStatus(419);
    }

    /**
     * @param  list<string>|null  $middleware
     * @return array<string, mixed>
     */
    private function render(string $uri = self::URI, ?array $middleware = null, bool $adminCode = false): array
    {
        $this->actingAsAdmin();
        Livewire::component('runtime.replay-counter', RuntimeCounterComponent::class);
        $panel = Filament::getPanel('admin');
        $register = static function () use ($uri, $middleware, $panel): void {
            Route::get($uri, static fn (): string => Livewire::mount('runtime.replay-counter'))
                ->middleware($middleware ?? [...$panel->getMiddleware(), ...$panel->getAuthMiddleware()]);
        };
        // Admin code outside every panel path is registered through Core's admin route helper.
        $adminCode ? AdminRouteProvenance::group($register) : $register();

        $html = $this->get($uri)->assertOk()->getContent();
        throw_unless(is_string($html), RuntimeException::class, 'Expected component HTML.');
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = html_entity_decode($matches[1] ?? throw new RuntimeException('Missing Livewire snapshot.'), ENT_QUOTES);

        return ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]]];
    }

    private function withoutRenderingRoute(): void
    {
        $this->replayRoutesWithout(self::URI);
    }

    private function replayRoutesWithout(string $uri): void
    {
        $routes = new RouteCollection;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ('/' . $route->uri() !== $uri) {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
    }

    /**
     * The snapshot as a component rendered before rendering routes were recorded carries it, validly signed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutRouteIdentity(array $payload): array
    {
        $snapshot = json_decode($payload['components'][0]['snapshot'], true, flags: JSON_THROW_ON_ERROR);
        unset($snapshot['memo'][InstalledPanelRuntime::SNAPSHOT_MEMO], $snapshot['checksum']);
        $snapshot['checksum'] = Checksum::generate($snapshot);
        $payload['components'][0]['snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function replay(array $payload): TestResponse
    {
        return $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), $payload, ['X-Livewire' => 'true']);
    }
}
