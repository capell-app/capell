<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Middleware\EnsureInstalledPanelAvailable;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeCounterComponent;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeWireBlockMiddleware;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
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

        $routes = new RouteCollection;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ('/' . $route->uri() !== self::URI) {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
        Livewire::flushState();

        $this->replay($payload)->assertStatus(419);
    }

    /** @return array<string, mixed> */
    private function render(): array
    {
        $this->actingAsAdmin();
        Livewire::component('runtime.replay-counter', RuntimeCounterComponent::class);
        $panel = Filament::getPanel('admin');
        Route::get(self::URI, static fn (): string => Livewire::mount('runtime.replay-counter'))
            ->middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()]);

        $html = $this->get(self::URI)->assertOk()->getContent();
        throw_unless(is_string($html), RuntimeException::class, 'Expected component HTML.');
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = html_entity_decode($matches[1] ?? throw new RuntimeException('Missing Livewire snapshot.'), ENT_QUOTES);

        return ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]]];
    }

    /** @param array<string, mixed> $payload */
    private function replay(array $payload): TestResponse
    {
        return $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), $payload, ['X-Livewire' => 'true']);
    }
}
