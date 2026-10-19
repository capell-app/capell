<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Contracts\Extenders\DeclaresFullPageOnlyMiddleware;
use Capell\Admin\Exceptions\PanelTopologyRefreshRequiredException;
use Capell\Admin\Http\Middleware\EnsureInstalledPanelAvailable;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Closure;
use Filament\Http\Middleware\IdentifyTenant;
use Filament\Http\Middleware\SetUpPanel;
use Filament\Panel;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Livewire\Livewire;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakMap;

/** Tracks application wiring per panel, independently of transient plugin instances. */
final class InstalledPanelRuntime
{
    /** @var WeakMap<Panel, array<int, bool>> */
    private WeakMap $applied;

    /** @var WeakMap<Panel, array{middleware: array<string>, auth: array<string>, tenant: array<string>}> */
    private WeakMap $routeMiddleware;

    /** @var array<string, true> */
    private array $unavailable = [];

    /** @var array<string, true> */
    private array $freshApplicationRequired = [];

    /** @var WeakMap<Panel, list<class-string>> */
    private WeakMap $fullPageOnly;

    /**
     * Routes already classified per panel id, with the name and declared middleware they were classified from.
     *
     * @var WeakMap<Route, array<string, list<mixed>>>
     */
    private WeakMap $inspectedRoutes;

    /** @var array{0: array<string, string>, 1: array<string, array<int, string>>}|null */
    private ?array $routerAliases = null;

    public function __construct(private readonly Router $router)
    {
        $this->applied = new WeakMap;
        $this->fullPageOnly = new WeakMap;
        $this->routeMiddleware = new WeakMap;
        $this->inspectedRoutes = new WeakMap;
    }

    public function isUnavailable(string $panel): bool
    {
        return isset($this->unavailable[$panel]);
    }

    /** The panel is withheld from this process only because its topology grew after boot. */
    public function requiresFreshApplication(string $panel): bool
    {
        return isset($this->freshApplicationRequired[$panel]);
    }

    /** @param Closure(): void $callback */
    public function guard(Panel $panel, Closure $callback): void
    {
        $id = new ReflectionProperty($panel, 'id')->isInitialized($panel) ? $panel->getId() : 'admin';
        if ($this->isUnavailable($id)) {
            throw new RuntimeException(__('capell-core::runtime-refresh.failed_application'));
        }

        $this->protectRoutes($panel, $id);
        try {
            $callback();
        } catch (PanelTopologyRefreshRequiredException $panelTopologyRefreshRequiredException) {
            // The wiring is valid; this process simply cannot rebuild routes it already built.
            $this->unavailable[$id] = true;
            $this->freshApplicationRequired[$id] = true;
            throw_if(app()->isBooted(), $panelTopologyRefreshRequiredException);
        } catch (Throwable $throwable) {
            $this->unavailable[$id] = true;
            resolve(InstalledRuntimeLifecycle::class)->recordFailure($throwable, 'capell-app/admin', self::class, 'admin', 'panel-refresh', $id);
            throw_if(app()->isBooted(), $throwable);
        }
    }

    public function extend(Panel $panel): void
    {
        $this->guard($panel, fn () => $this->apply($panel));
    }

    private function protectRoutes(Panel $panel, string $id): void
    {
        $middleware = EnsureInstalledPanelAvailable::class . ':' . $id;
        if (new ReflectionProperty($panel, 'id')->isInitialized($panel) && ! in_array($middleware, $panel->getMiddleware(), true)) {
            $panel->middleware([$middleware], isPersistent: true);
        }

        Livewire::addPersistentMiddleware([EnsureInstalledPanelAvailable::class]);
        // Classifying a route resolves and sorts its whole middleware stack. A route is only
        // reclassified when its name or declared middleware changed, or when the router's
        // aliases or groups (which decide what the declared names resolve to) changed.
        $aliases = [$this->router->getMiddleware(), $this->router->getMiddlewareGroups()];
        if ($this->routerAliases !== $aliases) {
            $this->inspectedRoutes = new WeakMap;
            $this->routerAliases = $aliases;
        }

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $signature = [$route->getName(), $route->getAction('middleware')];
            $inspected = $this->inspectedRoutes[$route] ?? [];
            if (isset($inspected[$id]) && $inspected[$id] === $signature) {
                continue;
            }

            $route->computedMiddleware = null;
            $declared = $this->router->resolveMiddleware($route->gatherMiddleware());
            if (str_starts_with((string) $route->getName(), 'filament.' . $id . '.')
                || in_array(SetUpPanel::class . ':' . $id, $declared, true)) {
                if (! in_array($middleware, $route->gatherMiddleware(), true)) {
                    $route->middleware($middleware);
                    $route->computedMiddleware = null;
                }

                $signature = [$route->getName(), $route->getAction('middleware')];
            }

            $inspected[$id] = $signature;
            $this->inspectedRoutes[$route] = $inspected;
        }
    }

    private function apply(Panel $panel): void
    {
        $hasId = new ReflectionProperty($panel, 'id')->isInitialized($panel);
        $baseline = $this->routeMiddleware[$panel] ?? [
            'middleware' => $hasId ? $panel->getMiddleware() : [],
            'auth' => $panel->getAuthMiddleware(),
            'tenant' => $panel->getTenantMiddleware(),
        ];
        $topology = [$panel->getPages(), $panel->getResources(), $panel->getWidgets()];
        $applied = $this->applied[$panel] ?? [];
        $index = 0;
        foreach (app()->tagged(AdminPanelExtender::TAG) as $extender) {
            $entry = $index++;
            if (! $extender instanceof AdminPanelExtender) {
                continue;
            }

            if (isset($applied[$entry])) {
                continue;
            }

            // Unchanged cleanup synchronisation is legal in an inherited sandbox.
            resolve(InstalledRuntimeLifecycle::class)->assertCanActivate();
            try {
                $extender->extend($panel);
            } catch (Throwable $exception) {
                $id = $hasId ? $panel->getId() : 'admin';
                $receipt = collect(resolve(ExtensionContributionReceiptRegistry::class)->all())
                    ->first(fn ($receipt): bool => $receipt->implementation === $extender::class);
                resolve(InstalledRuntimeLifecycle::class)->recordFailure($exception, $receipt->ownerPackage ?? 'capell-app/admin', $receipt->sourceClass ?? $extender::class, 'admin', 'panel-extender', $id);
                throw $exception;
            }

            if ($extender instanceof DeclaresFullPageOnlyMiddleware) {
                $this->fullPageOnly[$panel] = [...($this->fullPageOnly[$panel] ?? []), ...$extender->fullPageOnlyMiddleware()];
            }

            $applied[$entry] = true;
            $this->applied[$panel] = $applied;
        }

        // Resolve messages only on failure: namespaces may not exist during panel bootstrap.
        if (app()->isBooted() && $topology !== [$panel->getPages(), $panel->getResources(), $panel->getWidgets()]) {
            throw PanelTopologyRefreshRequiredException::make();
        }

        if (! $hasId) {
            return;
        }

        $current = ['middleware' => $panel->getMiddleware(), 'auth' => $panel->getAuthMiddleware(), 'tenant' => $panel->getTenantMiddleware()];
        $added = [];
        foreach ($current as $bucket => $middleware) {
            if (array_diff($baseline[$bucket], $middleware) !== []) {
                throw new RuntimeException(__('capell-admin::message.extension_panel_middleware_removal'));
            }

            $added[$bucket] = array_values(array_diff($middleware, $baseline[$bucket]));
        }

        // Persist resolved classes (including groups and parameterised middleware).
        // Livewire filters the real route pipeline, preserving its order and arguments.
        $persistent = $this->router->resolveMiddleware([...$added['middleware'], ...$added['auth'], ...$added['tenant']]);
        $security = $this->router->resolveMiddleware([...$current['auth'], ...$current['tenant']]);
        $replayed = array_diff(array_map(static fn (string $middleware): string => explode(':', $middleware, 2)[0], [...$persistent, ...$security]), $this->fullPageOnly[$panel] ?? []);
        Livewire::addPersistentMiddleware(array_values($replayed));

        if ($persistent !== []) {
            $routes = $this->router->getRoutes();
            foreach ($routes->getRoutes() as $route) {
                $name = $route->getName();
                // Compiled collections cache their named route objects separately.
                $instances = [$route];
                if ($name !== null && ($named = $routes->getByName($name)) instanceof Route && $named !== $route) {
                    $instances[] = $named;
                }

                foreach ($instances as $instance) {
                    $resolved = $this->router->gatherRouteMiddleware($instance);
                    // Exclusions must not hide the route's declared panel association.
                    $declared = $this->router->resolveMiddleware($instance->gatherMiddleware());
                    $associated = str_starts_with((string) $name, 'filament.' . $panel->getId() . '.')
                        || in_array(SetUpPanel::class . ':' . $panel->getId(), $declared, true)
                        || in_array('panel:' . $panel->getId(), $instance->gatherMiddleware(), true);
                    if (! $associated) {
                        continue;
                    }

                    if ($added['auth'] !== [] && $baseline['auth'] === []) {
                        throw new RuntimeException(__('capell-admin::message.extension_panel_authentication_ambiguous'));
                    }

                    $auth = $this->router->resolveMiddleware($baseline['auth']);
                    $tenant = $this->router->resolveMiddleware($baseline['tenant']);
                    $authentication = array_values(array_filter($auth, static fn (string $middleware): bool => is_a(explode(':', $middleware, 2)[0], Authenticate::class, true)));
                    $authentication = $authentication !== [] ? $authentication : $auth;
                    if (array_intersect($authentication, $declared) !== array_intersect($authentication, $resolved)
                        || array_intersect($tenant, $declared) !== array_intersect($tenant, $resolved)) {
                        throw new RuntimeException(__('capell-admin::message.extension_panel_authentication_excluded'));
                    }

                    $authenticated = $authentication !== [] && array_diff($authentication, $resolved) === [];
                    $tenanted = in_array(IdentifyTenant::class, $resolved, true);
                    // Partial authentication/tenancy or exclusions of new security
                    // middleware are ambiguous: do not silently publish weaker routes.
                    if (($added['auth'] !== [] && array_intersect($auth, $resolved) !== [] && ! $authenticated)
                        || ($added['tenant'] !== [] && array_intersect($tenant, $resolved) !== [] && ! $tenanted)) {
                        throw new RuntimeException(__('capell-admin::message.extension_panel_coverage_incomplete'));
                    }

                    $required = [...$added['middleware'], ...($authenticated ? $added['auth'] : []), ...($tenanted ? $added['tenant'] : [])];
                    $instance->middleware($required);
                    $instance->computedMiddleware = null;
                    if (array_diff($this->router->resolveMiddleware($required), $this->router->gatherRouteMiddleware($instance)) !== []) {
                        throw new RuntimeException(__('capell-admin::message.extension_panel_security_excluded'));
                    }
                }
            }
        }

        $this->routeMiddleware[$panel] = $current;
    }
}
