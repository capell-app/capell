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
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakMap;

/** Tracks application wiring per panel, independently of transient plugin instances. */
final class InstalledPanelRuntime
{
    /** Filament panel routes that serve visitors who have not signed in yet. */
    private const array PUBLIC_PANEL_ROUTES = [
        'auth.login',
        'auth.password-reset.request',
        'auth.password-reset.reset',
        'auth.register',
        'auth.email-verification.prompt',
        'auth.email-verification.verify',
    ];

    /** @var WeakMap<Panel, array<int, bool>> */
    private WeakMap $applied;

    /** @var WeakMap<Panel, array{middleware: array<string>, auth: array<string>, tenant: array<string>, booted: bool}> */
    private WeakMap $routeMiddleware;

    /**
     * Middleware contributed by packages (extenders, or runtime growth after boot), kept apart
     * from the host panel provider's own middleware.
     *
     * @var WeakMap<Panel, array{middleware: list<string>, auth: list<string>, tenant: list<string>}>
     */
    private WeakMap $contributed;

    /** @var WeakMap<Route, list<mixed>> */
    private WeakMap $reconciledRoutes;

    /**
     * Routes refused because their coverage could not be established. Only the route is
     * withheld; the panel stays available for every other route.
     *
     * @var WeakMap<Route, true>
     */
    private WeakMap $refusedRoutes;

    /** @var array<string, Panel> Panels whose routes have been reconciled after boot, by id. */
    private array $synchronised = [];

    /** @var array<string, true> */
    private array $unavailable = [];

    /** @var array<string, true> Route names their owners declared public, such as signed preview links. */
    private array $publicRoutes = [];

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
        $this->contributed = new WeakMap;
        $this->reconciledRoutes = new WeakMap;
        $this->refusedRoutes = new WeakMap;
        $this->inspectedRoutes = new WeakMap;
    }

    public function isUnavailable(string $panel): bool
    {
        return isset($this->unavailable[$panel]);
    }

    /**
     * Declare named routes under a panel path that are deliberately reachable without signing in,
     * such as signed preview links or assets the sign-in page loads. Every other route a panel
     * owns is held to the panel's authentication stack.
     */
    public function allowPublicRoute(string ...$names): void
    {
        foreach ($names as $name) {
            $this->publicRoutes[$name] = true;
        }
    }

    /** The route could not be held to its panel's security middleware, so it is never served. */
    public function isRouteRefused(?Route $route): bool
    {
        return $route instanceof Route && isset($this->refusedRoutes[$route]);
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

    /**
     * Reconcile a route as the router matches it, covering routes registered after the last
     * panel synchronisation (for example by a provider registered later in the process).
     * A route that cannot be covered is refused here, before its middleware runs, so no
     * middleware exclusion can serve it.
     */
    public function reconcileMatchedRoute(Route $route): void
    {
        if (! app()->isBooted()) {
            return;
        }

        $owners = $this->owners($route);
        if (count($owners) > 1) {
            $this->refuse(reset($owners), $route, new RuntimeException(__('capell-admin::message.extension_panel_route_ambiguous')));
        }

        foreach ($owners as $id => $panel) {
            $availability = EnsureInstalledPanelAvailable::class . ':' . $id;
            if (! in_array($availability, $route->middleware(), true)) {
                $route->middleware($availability);
                $route->computedMiddleware = null;
            }

            abort_if($this->isUnavailable($id), 503, __('capell-core::runtime-refresh.application_unavailable'));
            $this->reconcileOrRefuse($panel, $route);
        }

        abort_if($this->isRouteRefused($route), 503, __('capell-core::runtime-refresh.application_unavailable'));
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
        $previous = $this->routeMiddleware[$panel] ?? null;
        $contributed = $this->contributed[$panel] ?? ['middleware' => [], 'auth' => [], 'tenant' => []];
        // Growth since a snapshot taken after boot came from runtime wiring, not the host panel
        // provider, so it is held to the same coverage as an extender contribution.
        if ($previous !== null && $previous['booted']) {
            $contributed = $this->merge($contributed, $this->difference($this->middlewareOf($panel, $hasId), $previous));
        }

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
            $before = $this->middlewareOf($panel, $hasId);
            try {
                $extender->extend($panel);
            } catch (Throwable $exception) {
                $id = $hasId ? $panel->getId() : 'admin';
                $receipt = collect(resolve(ExtensionContributionReceiptRegistry::class)->all())
                    ->first(fn ($receipt): bool => $receipt->implementation === $extender::class);
                resolve(InstalledRuntimeLifecycle::class)->recordFailure($exception, $receipt->ownerPackage ?? 'capell-app/admin', $receipt->sourceClass ?? $extender::class, 'admin', 'panel-extender', $id);
                throw $exception;
            }

            $after = $this->middlewareOf($panel, $hasId);
            foreach ($before as $bucket => $middleware) {
                if (array_diff($middleware, $after[$bucket]) !== []) {
                    throw new RuntimeException(__('capell-admin::message.extension_panel_middleware_removal'));
                }
            }

            // Every package contribution is tracked apart from the host panel's own middleware,
            // whenever it arrives, so routes outside the panel's route table can be held to it.
            $contributed = $this->merge($contributed, $this->difference($after, $before));
            $this->contributed[$panel] = $contributed;

            if ($extender instanceof DeclaresFullPageOnlyMiddleware) {
                $this->fullPageOnly[$panel] = [...($this->fullPageOnly[$panel] ?? []), ...$extender->fullPageOnlyMiddleware()];
            }

            $applied[$entry] = true;
            $this->applied[$panel] = $applied;
        }

        $this->contributed[$panel] = $contributed;

        // Resolve messages only on failure: namespaces may not exist during panel bootstrap.
        if (app()->isBooted() && $topology !== [$panel->getPages(), $panel->getResources(), $panel->getWidgets()]) {
            throw PanelTopologyRefreshRequiredException::make();
        }

        if (! $hasId) {
            return;
        }

        $current = $this->middlewareOf($panel, true);
        if ($previous !== null) {
            foreach (['middleware', 'auth', 'tenant'] as $bucket) {
                if (array_diff($previous[$bucket], $current[$bucket]) !== []) {
                    throw new RuntimeException(__('capell-admin::message.extension_panel_middleware_removal'));
                }
            }
        }

        $this->routeMiddleware[$panel] = [...$current, 'booted' => app()->isBooted()];

        // Persist resolved classes (including groups and parameterised middleware).
        // Livewire filters the real route pipeline, preserving its order and arguments.
        $persistent = $this->router->resolveMiddleware([...$contributed['middleware'], ...$contributed['auth'], ...$contributed['tenant']]);
        $security = $this->router->resolveMiddleware([...$current['auth'], ...$current['tenant']]);
        $replayed = array_diff(array_map(static fn (string $middleware): string => explode(':', $middleware, 2)[0], [...$persistent, ...$security]), $this->fullPageOnly[$panel] ?? []);
        Livewire::addPersistentMiddleware(array_values($replayed));

        // Before boot the host panel provider is still configuring the panel and its routes are
        // built later from the finished panel, so coverage is established once it has booted.
        if (! app()->isBooted()) {
            return;
        }

        $this->synchronised[$panel->getId()] = $panel;
        $routes = $this->router->getRoutes();
        foreach ($routes->getRoutes() as $route) {
            $name = $route->getName();
            // Compiled collections cache their named route objects separately.
            $instances = [$route];
            if ($name !== null && ($named = $routes->getByName($name)) instanceof Route && $named !== $route) {
                $instances[] = $named;
            }

            foreach ($instances as $instance) {
                $owners = $this->owners($instance);
                if (! isset($owners[$panel->getId()])) {
                    continue;
                }

                if (count($owners) > 1) {
                    $this->refuse($panel, $instance, new RuntimeException(__('capell-admin::message.extension_panel_route_ambiguous')));

                    continue;
                }

                $this->reconcileOrRefuse($panel, $instance);
            }
        }
    }

    /** Fail closed for this route only: the failure is recorded and the route is refused. */
    private function reconcileOrRefuse(Panel $panel, Route $route): void
    {
        if (isset($this->refusedRoutes[$route])) {
            return;
        }

        try {
            $this->reconcileRoute($panel, $route);
        } catch (Throwable $throwable) {
            $this->refuse($panel, $route, $throwable);
        }
    }

    private function refuse(Panel $panel, Route $route, Throwable $throwable): void
    {
        if (isset($this->refusedRoutes[$route])) {
            return;
        }

        $this->refusedRoutes[$route] = true;
        // Keyed by route, so a refused route never masks a later failure of the panel itself.
        resolve(InstalledRuntimeLifecycle::class)->recordFailure($throwable, 'capell-app/admin', self::class, 'admin', 'panel-route-coverage', $panel->getId() . ':' . ($route->getName() ?? $route->uri()));
        Log::warning(__('capell-admin::message.extension_panel_route_refused', ['route' => $route->getName() ?? $route->uri()]), [
            'panel' => $panel->getId(),
            'reason' => $throwable->getMessage(),
        ]);
    }

    /**
     * Hold one panel route to every package security contribution: authenticated routes carry
     * the contributed authentication middleware and tenanted routes the contributed tenancy
     * middleware. Missing middleware is added; coverage that cannot be established is refused.
     */
    private function reconcileRoute(Panel $panel, Route $instance): void
    {
        $contributed = $this->contributed[$panel] ?? ['middleware' => [], 'auth' => [], 'tenant' => []];
        $current = $this->middlewareOf($panel, true);
        $host = [
            'middleware' => array_values(array_diff($current['middleware'], $contributed['middleware'])),
            'auth' => array_values(array_diff($current['auth'], $contributed['auth'])),
            'tenant' => array_values(array_diff($current['tenant'], $contributed['tenant'])),
        ];
        $signature = [$panel->getId(), $instance->getName(), $instance->getAction('middleware'), $instance->excludedMiddleware(), $contributed, $host, $this->isPublicPanelRoute($instance, $panel->getId())];
        if (($this->reconciledRoutes[$instance] ?? null) === $signature) {
            return;
        }

        // The availability guard is what denies a withheld panel; excluding it removes that denial.
        $availability = EnsureInstalledPanelAvailable::class . ':' . $panel->getId();
        if (! in_array($availability, $instance->middleware(), true)) {
            $instance->middleware($availability);
        }

        $instance->computedMiddleware = null;
        if (! in_array($availability, $this->router->gatherRouteMiddleware($instance), true)) {
            throw new RuntimeException(__('capell-admin::message.extension_panel_security_excluded'));
        }

        $public = $this->isPublicPanelRoute($instance, $panel->getId());
        if (! $public && $contributed['auth'] !== [] && $host['auth'] === []) {
            throw new RuntimeException(__('capell-admin::message.extension_panel_authentication_ambiguous'));
        }

        // Exclusions must not hide the route's declared tenancy.
        $declared = $this->router->resolveMiddleware($instance->gatherMiddleware());
        $tenanted = in_array(IdentifyTenant::class, $declared, true);
        // Every route the panel owns runs like the panel's own routes: the host panel's request
        // stack (which sets up the panel and its session), its authentication and the package
        // contributions. Only Filament's own public authentication routes, and routes the owner
        // declared public, skip the panel stack and authentication.
        $isMissing = fn (string $middleware): bool => array_diff($this->router->resolveMiddleware([$middleware]), $declared) !== [];
        $stack = $public ? [] : array_values(array_filter($host['middleware'], $isMissing));
        $required = [
            ...($public ? [] : $host['middleware']),
            ...$contributed['middleware'],
            ...($public ? [] : [...$host['auth'], ...$contributed['auth']]),
            ...($tenanted ? [...$host['tenant'], ...$contributed['tenant']] : []),
        ];
        $missing = array_values(array_filter(array_slice($required, $public ? 0 : count($host['middleware'])), $isMissing));
        if ($stack !== [] || $missing !== []) {
            $instance->action['middleware'] = [...$stack, ...$instance->middleware(), ...$missing];
            $instance->computedMiddleware = null;
        }

        // Verify the pipeline that will actually run, after exclusions.
        if (array_diff($this->router->resolveMiddleware($required), $this->router->gatherRouteMiddleware($instance)) !== []) {
            throw new RuntimeException(__('capell-admin::message.extension_panel_security_excluded'));
        }

        $this->reconciledRoutes[$instance] = [$panel->getId(), $instance->getName(), $instance->getAction('middleware'), $instance->excludedMiddleware(), $contributed, $host, $public];
    }

    /**
     * The synchronised panels a route belongs to: those it names or declares, and the panel
     * whose path (on one of its domains) holds it. More than one owner is ambiguous.
     *
     * @return array<string, Panel>
     */
    private function owners(Route $route): array
    {
        $owners = [];
        $byPath = [];
        $longest = -1;
        foreach ($this->synchronised as $id => $panel) {
            if ($this->associated($route, $id)) {
                $owners[$id] = $panel;

                continue;
            }

            $length = $this->pathOwnership($route, $panel);
            if ($length === null) {
                continue;
            }

            if ($length < $longest) {
                continue;
            }

            $byPath = $length > $longest ? [$id => $panel] : [...$byPath, $id => $panel];
            $longest = $length;
        }

        // Ownership by name or declaration and ownership by path must agree on one panel.
        return $owners + $byPath;
    }

    /** The length of the panel path holding the route, or null when the panel's path or domains exclude it. */
    private function pathOwnership(Route $route, Panel $panel): ?int
    {
        $path = trim($panel->getPath(), '/');
        // A panel served from the site root would claim every route; it owns only what it names.
        if ($path === '') {
            return null;
        }

        $uri = trim($route->uri(), '/');
        if ($uri !== $path && ! str_starts_with($uri, $path . '/')) {
            return null;
        }

        $domains = $panel->getDomains();
        $domain = $route->getDomain();
        if ($domains !== [] && $domain !== null && ! in_array($domain, $domains, true)) {
            return null;
        }

        return strlen($path);
    }

    /** Filament's own sign-in, password-reset, registration and email-verification routes, and declared public routes. */
    private function isPublicPanelRoute(Route $route, string $id): bool
    {
        $name = (string) $route->getName();
        if (isset($this->publicRoutes[$name])) {
            return true;
        }

        if (! str_starts_with($name, 'filament.' . $id . '.')) {
            return false;
        }

        // Panels on several domains insert the domain between the panel id and the route.
        return array_any(self::PUBLIC_PANEL_ROUTES, static fn (string $suffix): bool => str_ends_with($name, '.' . $suffix));
    }

    private function associated(Route $route, string $id): bool
    {
        if (str_starts_with((string) $route->getName(), 'filament.' . $id . '.')
            || in_array('panel:' . $id, $route->gatherMiddleware(), true)) {
            return true;
        }

        return in_array(SetUpPanel::class . ':' . $id, $this->router->resolveMiddleware($route->gatherMiddleware()), true);
    }

    /** @return array{middleware: array<string>, auth: array<string>, tenant: array<string>} */
    private function middlewareOf(Panel $panel, bool $hasId): array
    {
        return [
            'middleware' => $hasId ? $panel->getMiddleware() : [],
            'auth' => $panel->getAuthMiddleware(),
            'tenant' => $panel->getTenantMiddleware(),
        ];
    }

    /**
     * @param  array{middleware: array<string>, auth: array<string>, tenant: array<string>}  $after
     * @param  array{middleware: array<string>, auth: array<string>, tenant: array<string>, booted?: bool}  $before
     * @return array{middleware: list<string>, auth: list<string>, tenant: list<string>}
     */
    private function difference(array $after, array $before): array
    {
        return [
            'middleware' => array_values(array_diff($after['middleware'], $before['middleware'])),
            'auth' => array_values(array_diff($after['auth'], $before['auth'])),
            'tenant' => array_values(array_diff($after['tenant'], $before['tenant'])),
        ];
    }

    /**
     * @param  array{middleware: list<string>, auth: list<string>, tenant: list<string>}  $contributed
     * @param  array{middleware: list<string>, auth: list<string>, tenant: list<string>}  $added
     * @return array{middleware: list<string>, auth: list<string>, tenant: list<string>}
     */
    private function merge(array $contributed, array $added): array
    {
        return [
            'middleware' => array_values(array_unique([...$contributed['middleware'], ...$added['middleware']])),
            'auth' => array_values(array_unique([...$contributed['auth'], ...$added['auth']])),
            'tenant' => array_values(array_unique([...$contributed['tenant'], ...$added['tenant']])),
        ];
    }
}
