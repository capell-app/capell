<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Middleware\EnsureInstalledPanelAvailable;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\Livewire\ShortCircuitingPersistentMiddleware;
use Capell\Admin\Support\ProvenanceRouteCollection;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeCounterComponent;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeDecoratingMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeForbiddenResponseMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeHeldResponseMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeLateForbiddenMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeWireBlockMiddleware;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Iterator;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** A Livewire update is held to the route that rendered the component, reconciled as that route stands now. */
final class InstalledPanelRuntimeLivewireReplayTest extends AdminTestCase
{
    private const string URI = '/admin/runtime-replay';

    /** @return Iterator<string, array{class-string, int, string}> */
    public static function shortCircuitingMiddleware(): Iterator
    {
        yield 'returned refusal' => [RuntimeForbiddenResponseMiddleware::class, 403, 'refused by panel middleware'];
        yield 'successful answer without the next middleware' => [RuntimeHeldResponseMiddleware::class, 200, 'held by panel middleware'];
        yield 'refusal after the next middleware' => [RuntimeLateForbiddenMiddleware::class, 403, 'refused after the route answered'];
    }

    /** @return Iterator<string, array{bool}> */
    public static function livewireUpdateRequests(): Iterator
    {
        yield 'Livewire header on another path' => [true];
        yield 'update endpoint without the header' => [false];
    }

    public function test_a_replay_runs_panel_middleware_gained_after_the_render(): void
    {
        $payload = $this->render();
        $this->replay($payload)->assertOk();

        // The replay honours middleware that aborts, as it does a returned response.
        Filament::getPanel('admin')->middleware([RuntimeWireBlockMiddleware::class]);
        Livewire::flushState();

        $this->replay($payload)->assertForbidden();
    }

    /** @param class-string $middleware */
    #[DataProvider('shortCircuitingMiddleware')]
    public function test_a_replay_ends_with_the_exact_response_a_middleware_returns_instead_of_running_the_update(string $middleware, int $status, string $body): void
    {
        $payload = $this->render();
        RuntimeCounterComponent::$increments = 0;

        Filament::getPanel('admin')->middleware([$middleware], isPersistent: true);
        Livewire::addPersistentMiddleware([$middleware]);
        Livewire::flushState();

        $response = $this->replay($payload);

        $response->assertStatus($status);
        $this->assertSame($body, $response->getContent());
        $this->assertSame(0, RuntimeCounterComponent::$increments, 'The update must stop before the action runs.');
    }

    public function test_a_replay_through_middleware_that_passes_the_request_on_runs_the_update(): void
    {
        $payload = $this->render();
        RuntimeCounterComponent::$increments = 0;

        Filament::getPanel('admin')->middleware([RuntimeDecoratingMiddleware::class], isPersistent: true);
        Livewire::addPersistentMiddleware([RuntimeDecoratingMiddleware::class]);
        Livewire::flushState();

        $this->replay($payload)->assertOk()->assertJsonPath('components.0.effects.html', fn (?string $html): bool => str_contains((string) $html, 'count: 1'));
        $this->assertSame(1, RuntimeCounterComponent::$increments);
    }

    public function test_the_short_circuiting_replay_stands_in_for_livewire_persistent_middleware(): void
    {
        $this->assertInstanceOf(ShortCircuitingPersistentMiddleware::class, resolve(PersistentMiddleware::class));
    }

    public function test_registering_the_admin_package_after_boot_fails_instead_of_leaving_updates_unguarded(): void
    {
        $this->expectException(LogicException::class);

        $this->app->register(new AdminServiceProvider($this->app), force: true);
    }

    /** During installation, a process that may be serving a Livewire update still refuses a late registration. */
    #[DataProvider('livewireUpdateRequests')]
    public function test_the_package_installer_may_not_register_the_admin_package_late_while_serving_a_livewire_update(bool $byHeader): void
    {
        $request = $byHeader
            ? Request::create('/install/run-step', Request::METHOD_POST, server: ['HTTP_X_LIVEWIRE' => 'true'])
            : Request::create(Livewire::getUpdateUri(), Request::METHOD_POST);

        $this->app->instance('request', $request);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The admin package must be registered before the application boots so Livewire updates honour middleware responses.');

        resolve(PackageSurfaceRegistrar::class)->duringPackageInstallation(
            fn (): object => $this->app->register(new AdminServiceProvider($this->app), force: true),
        );
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
        // Routes on the panel's path are registered in a group running the panel's middleware, as the panel's own are.
        $register = static function () use ($uri, $middleware, $panel): void {
            Route::middleware($middleware ?? [...$panel->getMiddleware(), ...$panel->getAuthMiddleware()])
                ->group(static fn () => Route::get($uri, static fn (): string => Livewire::mount('runtime.replay-counter')));
        };
        // Admin code outside every panel path is registered for the admin panel.
        $adminCode ? AdminRouteProvenance::panel(AdminPanelEntrypoint::PANEL, $register) : $register();

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
        $routes = new ProvenanceRouteCollection;
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
