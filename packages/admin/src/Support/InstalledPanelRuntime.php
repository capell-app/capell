<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Contracts\Extenders\DeclaresFullPageOnlyMiddleware;
use Capell\Admin\Enums\RouteOwnershipEnum;
use Capell\Admin\Exceptions\PanelTopologyRefreshRequiredException;
use Capell\Admin\Http\Controllers\Transfers\DownloadExportInOriginatingPanelController;
use Capell\Admin\Http\Controllers\Transfers\DownloadImportFailuresInOriginatingPanelController;
use Capell\Admin\Http\Middleware\EnsureInstalledPanelAvailable;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Closure;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Filament\Http\Middleware\IdentifyTenant;
use Filament\Http\Middleware\SetUpPanel;
use Filament\Pages\BasePage;
use Filament\Panel;
use Filament\PanelRegistry;
use Guava\IconPicker\Http\Controllers\IconIndexController;
use Guava\IconPicker\Http\Controllers\IconSvgController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteAction;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Features\SupportCssModules\SupportCssModules;
use Livewire\Features\SupportFileUploads\FilePreviewController;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Features\SupportJsModules\SupportJsModules;
use Livewire\Features\SupportTesting\InitialRender;
use Livewire\Livewire;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Livewire\Mechanisms\HandleRouting\LivewirePageController;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\CompiledRoute;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Throwable;
use WeakMap;

/** Tracks application wiring per panel, independently of transient plugin instances. */
final class InstalledPanelRuntime
{
    /** The snapshot memo key recording the route that rendered a Livewire component. */
    public const string SNAPSHOT_MEMO = 'capellRoute';

    /**
     * Filament's sign-in, password-reset and registration pages, which serve visitors who cannot have signed
     * in yet. Email verification serves signed-in users, so it keeps the panel's authentication.
     */
    private const array SIGN_IN_PAGES = [Login::class, RequestPasswordReset::class, ResetPassword::class, Register::class];

    /**
     * Packages that only serve the admin panels, by namespace: Filament, Livewire, Capell admin and the
     * admin-only Filament plugins the panels install. A route whose action is shipped in their source or
     * route files must be registered by a panel or declared public, or it is refused.
     */
    private const string CAPELL_ADMIN_NAMESPACE = 'Capell\\Admin\\';

    private const array ADMIN_NAMESPACES = ['Filament\\', 'Livewire\\', 'Capell\\Admin\\', 'Guava\\IconPicker\\', 'Pboivin\\FilamentPeek\\', 'STS\\FilamentImpersonate\\'];

    /**
     * Admin code that is public because something other than a session is its credential. Livewire's own
     * endpoints serve every page, signed in or not: the update endpoint replays a panel component's panel
     * middleware for the route that rendered it, uploads and previews are credentialed by their URL
     * signature, and the rest serve Livewire's static script, maps and component assets. Filament's global
     * transfer downloads only send the visitor on to the copy of the route in the originating panel.
     */
    private const array PUBLIC_ACTIONS = [
        HandleRequests::class,
        FileUploadController::class,
        FilePreviewController::class,
        FrontendAssets::class,
        SupportCssModules::class,
        SupportJsModules::class,
        // The component-testing endpoint, which Livewire::test() registers.
        InitialRender::class,
        DownloadExportInOriginatingPanelController::class,
        DownloadImportFailuresInOriginatingPanelController::class,
    ];

    /**
     * Admin plugin route files whose closures are public: the internal preview, whose per-preview grant
     * admits only the signed-in user and panel it was issued to (AuthorisePeekPreview), and leaving an
     * impersonation, which serves the impersonated user, who need not have panel access.
     */
    private const array PUBLIC_ROUTE_FILES = [
        'pboivin/filament-peek' => 'routes/preview.php',
        'stechstudio/filament-impersonate' => 'routes/web.php',
    ];

    /** Admin plugin endpoints that only serve the Capell admin panel's forms, such as the icon picker's. */
    private const array ENTRYPOINT_ACTIONS = [IconIndexController::class, IconSvgController::class];

    /** Authentication declared by the registration group, retained if an alias later changes. */
    private const string AUTHENTICATED_ACTION = 'capell_panel_authenticated';

    /** A page gaining authentication solely because it runs admin code, retained through reconciliation and route caching. */
    private const string PAGE_AUTHENTICATION_ACTION = 'capell_panel_page_authentication';

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

    /**
     * The rendering route carried by the snapshots of the Livewire update being handled.
     *
     * @var array{route: string, security: list<string>}|null
     */
    private ?array $replayedRoute = null;

    /**
     * Rendering identities computed during the current request, by route, so the components of one page share one.
     *
     * @var WeakMap<Route, array{route: string, security: list<string>}>
     */
    private WeakMap $renderings;

    /** @var array<string, string|null>|null The admin packages' source and route directories, with the namespace each source directory holds. */
    private ?array $adminRoots = null;

    /** @var array<string, true> */
    private array $freshApplicationRequired = [];

    /** @var WeakMap<Panel, list<class-string>> */
    private WeakMap $fullPageOnly;

    /** The panel that recorded ownership of the route the current Livewire update replays, if a panel's. */
    private ?string $replayedPanel = null;

    /** Whether this process has already reported serving from a route cache built before ownership was recorded. */
    private bool $staleCacheReported = false;

    /** @var list<mixed>|null Last completely classified route table and security configuration. */
    private ?array $classifiedSignature = null;

    public function __construct(private readonly Router $router)
    {
        $this->applied = new WeakMap;
        $this->fullPageOnly = new WeakMap;
        $this->routeMiddleware = new WeakMap;
        $this->contributed = new WeakMap;
        $this->reconciledRoutes = new WeakMap;
        $this->refusedRoutes = new WeakMap;
        $this->renderings = new WeakMap;
    }

    public function isUnavailable(string $panel): bool
    {
        return isset($this->unavailable[$panel]);
    }

    /**
     * Decide and record a route's ownership as it is added to the router's collection, while its action can
     * still be inspected. It is decided once: a route that already carries an ownership keeps it, and the
     * record travels with the route through `route:cache`, so a cached closure is never inspected.
     */
    public function recordOwnership(Route $route): void
    {
        if (! PanelOwnership::of($route) instanceof PanelOwnership) {
            $this->ownershipAtRegistration($route)->recordOn($route);
        }

        $ownership = PanelOwnership::of($route);
        if ($ownership?->kind === RouteOwnershipEnum::Panel && $route->getAction(self::AUTHENTICATED_ACTION) === null) {
            $declared = $this->router->resolveMiddleware($route->middleware());
            // Do not resolve the registry before Filament's providers have registered their panels.
            $registered = app()->resolved(PanelRegistry::class) ? Filament::getPanels() : [];
            $panel = $registered[$ownership->panel] ?? $this->synchronised[$ownership->panel] ?? null;
            $route->action[self::AUTHENTICATED_ACTION] = array_any($declared, $this->isAuthenticator(...))
                || ($panel instanceof Panel && array_intersect($declared, $this->router->resolveMiddleware($panel->getAuthMiddleware())) !== []);
        }

        $this->demoteLegacyHost($route);
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
     * Serve a matched route as its recorded ownership says, before its middleware runs, so no middleware
     * exclusion can serve a refused route: a panel's route runs that panel's whole stack, and a route the
     * ownership cannot vouch for is refused.
     */
    public function reconcileMatchedRoute(Route $route): void
    {
        if (! app()->isBooted()) {
            return;
        }

        ProvenanceRouteCollection::install($this->router);
        $this->demoteLegacyHost($route);
        $this->refreshRefusal($route);

        $ownership = PanelOwnership::of($route);
        $panel = $ownership instanceof PanelOwnership && $ownership->kind === RouteOwnershipEnum::Panel ? $ownership->panel : null;
        abort_if($panel !== null && $this->isUnavailable($panel), 503, __('capell-core::runtime-refresh.application_unavailable'));
        if ($panel !== null) {
            // Every panel's route, extended by Capell or not, is withheld while the installed runtime is unavailable.
            $availability = EnsureInstalledPanelAvailable::class . ':' . $panel;
            if (! in_array($availability, $route->middleware(), true)) {
                $route->middleware($availability);
                $route->computedMiddleware = null;
            }
        }

        $owner = $this->ownerOf($route);
        if ($owner instanceof Panel && ! $this->isRouteRefused($route)) {
            $this->reconcileOrRefuse($owner, $route);
        }

        abort_if($this->isRouteRefused($route), 503, __('capell-core::runtime-refresh.application_unavailable'));
    }

    /**
     * Reconcile the route that rendered a Livewire component before its update replays that route's
     * persistent middleware, so the replay runs the route as it stands now and a refused route is
     * refused, whatever middleware the route excludes.
     */
    public function reconcileLivewireReplay(Request $request, string $path, string $method, mixed $renderedBy): void
    {
        $server = clone $request->server;
        $server->set('REQUEST_URI', str_replace('/' . ltrim($request->path(), '/'), '/' . ltrim($path, '/'), (string) $server->get('REQUEST_URI')));
        $server->set('REQUEST_METHOD', $method);

        try {
            $route = $this->router->getRoutes()->match($request->duplicate(server: $server->all()));
        } catch (HttpExceptionInterface) {
            // Livewire would replay no middleware at all for a route that no longer matches, so the
            // stale snapshot is rejected as expired.
            abort(419);
        }

        // The snapshot is signed with the route that rendered it and the panels that owned that route. A
        // different route answering the same path now, such as a public catch-all once an admin route
        // is removed, is not that route; nor is the same route after a panel stopped owning it. A panel
        // that owns the route since the render only adds its stack to the replay.
        // A snapshot rendered before rendering routes were recorded cannot prove which route, or which
        // panels, rendered it, so it is rejected as expired and the page is reloaded.
        $current = $this->rendering($route);
        abort_unless(is_array($renderedBy) && is_string($renderedBy['route'] ?? null) && is_array($renderedBy['security'] ?? null), 419);
        abort_unless(hash_equals($current['route'], $renderedBy['route']), 419);
        abort_unless(array_diff($renderedBy['security'], $current['security']) === [], 419);

        $this->replayedRoute = $current;
        $ownership = PanelOwnership::of($route);
        $this->replayedPanel = $ownership?->kind === RouteOwnershipEnum::Panel ? $ownership->panel : null;
        $this->reconcileMatchedRoute($route);
    }

    /**
     * The identity of the route rendering a Livewire component, recorded in its signed snapshot memo: on
     * a Livewire update, that of the route the update replays, so child components keep it.
     *
     * @return array{route: string, security: list<string>}|null
     */
    public function renderingRouteIdentity(Request $request): ?array
    {
        if (resolve(HandleRequests::class)->isLivewireRoute()) {
            return $this->replayedRoute;
        }

        $route = $request->route();

        return $route instanceof Route ? ($this->renderings[$route] ??= $this->rendering($route)) : null;
    }

    /** Forget the replayed rendering route and this request's rendering identities at the end of a request. */
    public function forgetReplayedRoute(): void
    {
        $this->replayedRoute = null;
        $this->replayedPanel = null;
        $this->renderings = new WeakMap;
    }

    /** The panel Capell extends with this id, if it is still registered and synchronised. */
    public function capellPanel(string $id): ?Panel
    {
        return $this->synchronised[$id] ?? null;
    }

    /**
     * The panel serving the request, from the recorded ownership of its route: on a Livewire update, of the
     * route the update replays. Null when no panel registered that route.
     */
    public function servingPanel(Request $request): ?string
    {
        if (resolve(HandleRequests::class)->isLivewireRoute()) {
            return $this->replayedPanel;
        }

        $route = $request->route();
        $ownership = $route instanceof Route ? PanelOwnership::of($route) : null;

        return $ownership?->kind === RouteOwnershipEnum::Panel ? $ownership->panel : null;
    }

    /**
     * Routes carrying no recorded ownership: a route cache built by a release before ownership was recorded.
     *
     * @return list<string>
     */
    public function unrecordedRoutes(): array
    {
        return $this->describe(array_filter(
            $this->router->getRoutes()->getRoutes(),
            static fn (Route $route): bool => ! PanelOwnership::of($route) instanceof PanelOwnership,
        ));
    }

    /**
     * Routes whose recorded ownership refuses them, wherever they are requested.
     *
     * @return list<string>
     */
    public function refusedRoutes(): array
    {
        $refused = [];
        $matchers = $this->panelPathMatchers();
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            try {
                if (PanelOwnership::of($route) instanceof PanelOwnership && $this->refusal($route, $matchers) !== null) {
                    $refused[] = $this->describe([$route])[0];
                }
            } catch (Throwable $throwable) {
                $refused[] = $this->describe([$route])[0] . ': ' . $throwable->getMessage();
            }
        }

        return $refused;
    }

    /**
     * Pages in a panel's unauthenticated routes() group that gain sign-in because they run admin code.
     * Explicit panel declarations already opt into the full stack; sign-in pages and guest declarations are exempt.
     *
     * @return list<string>
     */
    public function pagesRequiringGuestDeclaration(): array
    {
        $pages = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $page = $route->getAction(self::PAGE_AUTHENTICATION_ACTION);
            if (is_string($page)) {
                $pages[] = $this->describe([$route])[0] . ' -> ' . PanelOwnership::of($route)?->panel . ' (' . $page . ')';
            }
        }

        return $pages;
    }

    /**
     * The host application's routes answering on a Capell panel's path, on a host that panel is served on.
     * They keep serving with their own middleware, but share the panel's URLs, so each is reported.
     *
     * @return list<string>
     */
    public function panelPathCollisions(): array
    {
        $collisions = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (PanelOwnership::of($route)?->kind !== RouteOwnershipEnum::Host) {
                continue;
            }

            $uri = trim($route->uri(), '/');
            foreach ($this->synchronised as $id => $panel) {
                $path = trim($panel->getPath(), '/');
                // A panel served from the site root registers every route it serves; the site's other routes are the host's.
                if ($path === '') {
                    continue;
                }

                if ($uri !== $path && ! str_starts_with($uri, $path . '/')) {
                    continue;
                }

                if (! $this->overlapsPanelDomains($route, $this->compilePanelDomains(array_values($panel->getDomains())))) {
                    continue;
                }

                $collisions[] = $this->describe([$route])[0] . ' -> ' . $id;
            }
        }

        return $collisions;
    }

    /**
     * A route's identity (name, methods, domain and URI) and the panels owning it, each opaque in public
     * HTML. A name route:cache generates is random per cache, so it does not identify a route.
     *
     * @return array{route: string, security: list<string>}
     */
    private function rendering(Route $route): array
    {
        $key = (string) config('app.key');
        $name = (string) $route->getName();
        $owner = $this->ownerOf($route);
        $owners = $owner instanceof Panel ? [hash_hmac('sha256', 'panel:' . $owner->getId(), $key)] : [];

        return [
            'route' => hash_hmac('sha256', (string) json_encode([
                str_starts_with($name, 'generated::') ? '' : $name,
                $route->methods(),
                $route->getDomain(),
                $route->uri(),
            ]), $key),
            'security' => $owners,
        ];
    }

    private function protectRoutes(Panel $panel, string $id): void
    {
        $middleware = EnsureInstalledPanelAvailable::class . ':' . $id;
        if (new ReflectionProperty($panel, 'id')->isInitialized($panel) && ! in_array($middleware, $panel->getMiddleware(), true)) {
            $panel->middleware([$middleware], isPersistent: true);
        }

        // Each route the panel registered gains the guard as it is matched, from its recorded ownership.
        Livewire::addPersistentMiddleware([EnsureInstalledPanelAvailable::class]);
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
        if ($this->classifiedSignature === $this->routeTableSignature()) {
            return;
        }

        $matchers = $this->panelPathMatchers();
        $routes = $this->router->getRoutes();
        foreach ($routes->getRoutes() as $route) {
            $name = $route->getName();
            // Compiled collections cache their named route objects separately.
            $instances = [$route];
            if ($name !== null && ($named = $routes->getByName($name)) instanceof Route && $named !== $route) {
                $instances[] = $named;
            }

            foreach ($instances as $instance) {
                $this->demoteLegacyHost($instance);
                $this->refreshRefusal($instance, $matchers);
                $owner = $this->ownerOf($instance);
                if ($owner instanceof Panel) {
                    $this->reconcileOrRefuse($owner, $instance);
                }
            }
        }

        // Reconciliation can add middleware, so memoise the finished table, not its earlier shape.
        $this->classifiedSignature = $this->routeTableSignature();
        $pages = $this->pagesRequiringGuestDeclaration();
        if ($pages !== []) {
            Log::warning(__('capell-admin::message.route_pages_require_authentication', ['routes' => implode(', ', $pages)]), ['pages' => $pages]);
        }
    }

    /**
     * A cheap value snapshot detects fluent changes as well as additions, replacement collections,
     * route-cache rebuilds and middleware alias/group or panel changes in long-lived workers.
     * No controller resolution, closure inspection or source reflection belongs in this signature.
     *
     * @return list<mixed>
     */
    private function routeTableSignature(): array
    {
        $routes = $this->router->getRoutes();
        $entries = [];
        foreach ($routes->getRoutes() as $route) {
            $instances = [$route];
            if (($name = $route->getName()) !== null && ($named = $routes->getByName($name)) instanceof Route && $named !== $route) {
                $instances[] = $named;
            }

            foreach ($instances as $instance) {
                $entries[] = [$routes instanceof CompiledRouteCollection ? null : spl_object_id($instance), $instance->uri(), $instance->methods(), $instance->getAction(), $instance->wheres];
            }
        }

        $panels = [];
        foreach ($this->synchronised as $id => $panel) {
            $panels[$id] = [$panel, $panel->getPath(), $panel->getDomains(), $this->middlewareOf($panel, true), $this->contributed[$panel] ?? []];
        }

        $cache = app()->getCachedRoutesPath();
        clearstatcache(true, $cache);

        return [$routes, $entries, $panels, array_keys(Filament::getPanels()), AdminPanelEntrypoint::path(), AdminPanelEntrypoint::domain(),
            $this->router->getMiddleware(), $this->router->getMiddlewareGroups(), $this->router->middlewarePriority, $this->router->getPatterns(),
            $cache, is_file($cache) ? filemtime($cache) : null];
    }

    /** Fail closed for this route only: the failure is recorded and the route is refused. */
    private function reconcileOrRefuse(Panel $panel, Route $route): void
    {
        if (isset($this->refusedRoutes[$route])) {
            return;
        }

        try {
            $this->absorbGrowth($panel);
            $this->reconcileRoute($panel, $route);
        } catch (Throwable $throwable) {
            $this->refuse($route, $throwable);
        }
    }

    /**
     * Middleware added to the panel since its last synchronisation came from runtime wiring, not
     * the host panel provider, so it is held to the same coverage as an extender contribution.
     */
    private function absorbGrowth(Panel $panel): void
    {
        $snapshot = $this->routeMiddleware[$panel] ?? null;
        if ($snapshot === null || ! $snapshot['booted']) {
            return;
        }

        $current = $this->middlewareOf($panel, true);
        foreach (['middleware', 'auth', 'tenant'] as $bucket) {
            if (array_diff($snapshot[$bucket], $current[$bucket]) !== []) {
                throw new RuntimeException(__('capell-admin::message.extension_panel_middleware_removal'));
            }
        }

        $growth = $this->difference($current, $snapshot);
        if ($growth === ['middleware' => [], 'auth' => [], 'tenant' => []]) {
            return;
        }

        $this->contributed[$panel] = $this->merge($this->contributed[$panel] ?? ['middleware' => [], 'auth' => [], 'tenant' => []], $growth);
        $this->routeMiddleware[$panel] = [...$current, 'booted' => true];
        $replayed = array_diff(
            array_map(static fn (string $middleware): string => explode(':', $middleware, 2)[0], $this->router->resolveMiddleware([...$growth['middleware'], ...$growth['auth'], ...$growth['tenant']])),
            $this->fullPageOnly[$panel] ?? [],
        );
        Livewire::addPersistentMiddleware(array_values($replayed));
    }

    private function refuse(Route $route, Throwable $throwable): void
    {
        if (isset($this->refusedRoutes[$route])) {
            return;
        }

        $this->refusedRoutes[$route] = true;
        // Laravel gathers middleware again during termination, even after the match listener returns
        // 503. A broken controller must not throw a second time while terminating that refusal.
        $route->computedMiddleware = [];
        $id = PanelOwnership::of($route)->panel ?? AdminPanelEntrypoint::PANEL;
        // Keyed by route, so a refused route never masks a later failure of the panel itself.
        resolve(InstalledRuntimeLifecycle::class)->recordFailure($throwable, 'capell-app/admin', self::class, 'admin', 'panel-route-coverage', $id . ':' . ($route->getName() ?? $route->uri()));
        Log::warning(__('capell-admin::message.extension_panel_route_refused', ['route' => $route->getName() ?? $route->uri()]), [
            'panel' => $id,
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
        // What the declared names resolve to is part of the coverage: aliases, groups and priority.
        $routerState = [$this->router->getMiddleware(), $this->router->getMiddlewareGroups(), $this->router->middlewarePriority];
        $signature = [$panel->getId(), $instance->getName(), $instance->getAction('middleware'), $instance->excludedMiddleware(), $contributed, $host, $routerState];
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

        // Inspect declarations independently of the effective pipeline and its cached exclusions.
        $declared = $this->router->resolveMiddleware([...$instance->middleware(), ...$instance->controllerMiddleware()]);
        // Filament's routes() group keeps non-admin callbacks and custom sign-in controllers public.
        // Admin actions and explicit provenance opt into the panel's full stack.
        $declaredAuthentication = $instance->getAction(self::AUTHENTICATED_ACTION) === true
            || array_intersect($declared, $this->router->resolveMiddleware($current['auth'])) !== []
            || array_any($declared, $this->isAuthenticator(...));
        $provenance = AdminRouteProvenance::declared($instance);
        if ($instance->getAction(self::PAGE_AUTHENTICATION_ACTION) === null) {
            // Decide before adding authentication, so later synchronisation and cached boots retain the upgrade warning.
            $instance->action[self::PAGE_AUTHENTICATION_ACTION] = ! $declaredAuthentication
                && ! (PanelOwnership::of($instance)->guests ?? false)
                && ! $this->isSignInPage($instance)
                && $provenance['panels'] === []
                    ? ($this->filamentPage($instance) ?? false)
                    : false;
        }

        $authenticated = $declaredAuthentication
            || $this->runsAdminCode($instance)
            || $provenance['panels'] !== [];
        // Retain declared authentication only: admin-code inference must not turn a public framework
        // action into an authenticated-group route on the next reconciliation.
        $instance->action[self::AUTHENTICATED_ACTION] = $declaredAuthentication;
        $public = (PanelOwnership::of($instance)->guests ?? false)
            || $this->isSignInPage($instance)
            || (! $declaredAuthentication && ($this->isPublicAction($instance) || $this->isPublicRouteFile($instance)))
            || ! $authenticated;
        if (! $public && $contributed['auth'] !== [] && $host['auth'] === []) {
            throw new RuntimeException(__('capell-admin::message.extension_panel_authentication_ambiguous'));
        }

        // Exclusions must not hide the route's declared tenancy.
        $tenanted = in_array(IdentifyTenant::class, $declared, true);
        // Non-admin callbacks in the unauthenticated group keep serving guests. Admin actions and
        // explicit standalone declarations carry the full stack unless they explicitly allow guests.
        $isMissing = fn (string $middleware): bool => array_diff($this->router->resolveMiddleware([$middleware]), $declared) !== [];
        $stack = $public ? [] : array_values(array_filter($host['middleware'], $isMissing));
        $required = [
            ...($public ? [] : $host['middleware']),
            ...$contributed['middleware'],
            ...($public ? [] : [...$host['auth'], ...$contributed['auth']]),
            ...($tenanted ? [...$host['tenant'], ...$contributed['tenant']] : []),
        ];
        $missingPanel = array_values(array_filter($contributed['middleware'], $isMissing));
        $missingSecurity = array_values(array_filter([
            ...($public ? [] : [...$host['auth'], ...$contributed['auth']]),
            ...($tenanted ? [...$host['tenant'], ...$contributed['tenant']] : []),
        ], $isMissing));
        $security = $this->router->resolveMiddleware([...$host['auth'], ...$contributed['auth'], ...$host['tenant'], ...$contributed['tenant']]);
        $panelStack = [...$this->router->resolveMiddleware([...$host['middleware'], ...$contributed['middleware']]), $availability, SetUpPanel::class, IdentifyTenant::class];
        if ($stack !== [] || $missingPanel !== [] || $missingSecurity !== []) {
            // The host panel's stack leads in the panel's own order, including the entries the route declares itself.
            $own = $instance->middleware();
            $leading = $public ? [] : array_values(array_filter($host['middleware'], static fn (string $middleware): bool => $isMissing($middleware) || in_array($middleware, $own, true)));
            $rest = array_values(array_filter($own, static fn (mixed $middleware): bool => ! in_array($middleware, $leading, true)));
            $instance->action['middleware'] = $this->placeInPanelOrder([...$leading, ...$rest], $missingPanel, $missingSecurity, $panelStack, $security);
            $instance->computedMiddleware = null;
        }

        // Verify the pipeline that will actually run, after exclusions and priority sorting.
        $pipeline = $this->router->gatherRouteMiddleware($instance);
        if (array_diff($this->router->resolveMiddleware($required), $pipeline) !== []) {
            throw new RuntimeException(__('capell-admin::message.extension_panel_security_excluded'));
        }

        $this->assertPanelOrder($pipeline, $host, $contributed, $public, $tenanted, [...$panelStack, ...$security]);

        $this->reconciledRoutes[$instance] = [$panel->getId(), $instance->getName(), $instance->getAction('middleware'), $instance->excludedMiddleware(), $contributed, $host, $routerState];
    }

    /**
     * Insert missing middleware where the panel would run it: package panel middleware after the
     * route's leading panel stack, and authentication and tenancy after the route's own
     * authentication, so a route middleware never answers before package security has run.
     *
     * @param  array<mixed>  $entries
     * @param  array<string>  $panelMiddleware
     * @param  array<string>  $securityMiddleware
     * @param  array<mixed>  $panelStack
     * @param  array<mixed>  $security
     * @return list<mixed>
     */
    private function placeInPanelOrder(array $entries, array $panelMiddleware, array $securityMiddleware, array $panelStack, array $security): array
    {
        $entries = array_values($entries);
        $known = [...array_values($panelStack), ...array_values($security)];
        $prefix = 0;
        while ($prefix < count($entries) && $this->isPanelEntry($entries[$prefix], $known)) {
            $prefix++;
        }

        $firstSecurity = null;
        $afterSecurity = null;
        foreach ($entries as $index => $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $resolved = $this->router->resolveMiddleware([$entry]);
            if (array_intersect($resolved, $security) !== [] || array_filter($resolved, $this->isAuthenticator(...)) !== []) {
                $firstSecurity ??= $index;
                $afterSecurity = $index + 1;
            }
        }

        $securityAt = $afterSecurity ?? $prefix;
        $panelAt = min($prefix, $firstSecurity ?? $prefix);
        array_splice($entries, $securityAt, 0, $securityMiddleware);
        array_splice($entries, $panelAt, 0, $panelMiddleware);

        return $entries;
    }

    /**
     * The host panel's authentication and package middleware must run before any of the route's own
     * middleware, which could answer the request first, and package authentication must run after the
     * panel has authenticated.
     *
     * @param  array<mixed>  $pipeline
     * @param  array{middleware: list<string>, auth: list<string>, tenant: list<string>}  $host
     * @param  array{middleware: list<string>, auth: list<string>, tenant: list<string>}  $contributed
     * @param  array<mixed>  $known
     */
    private function assertPanelOrder(array $pipeline, array $host, array $contributed, bool $public, bool $tenanted, array $known): void
    {
        $pipeline = array_values($pipeline);
        $foreign = count($pipeline);
        foreach ($pipeline as $index => $middleware) {
            if (! $this->isPanelEntry($middleware, $known)) {
                $foreign = $index;

                break;
            }
        }

        $packageSecurity = $this->router->resolveMiddleware([...($public ? [] : $contributed['auth']), ...($tenanted ? $contributed['tenant'] : [])]);
        foreach ([...$this->router->resolveMiddleware($contributed['middleware']), ...$packageSecurity] as $middleware) {
            if (array_search($middleware, $pipeline, true) > $foreign) {
                throw new RuntimeException(__('capell-admin::message.extension_panel_security_order'));
            }
        }

        $hostAuth = $this->router->resolveMiddleware($host['auth']);
        // The panel authenticates before any of the route's own middleware could answer the request.
        if (! $public) {
            foreach ($hostAuth as $middleware) {
                $position = array_search($middleware, $pipeline, true);
                if ($position !== false && $position > $foreign) {
                    throw new RuntimeException(__('capell-admin::message.extension_panel_security_order'));
                }
            }
        }

        $authenticators = array_values(array_filter($hostAuth, $this->isAuthenticator(...)));
        $authenticated = -1;
        foreach ($authenticators !== [] ? $authenticators : $hostAuth as $middleware) {
            $authenticated = max($authenticated, (int) array_search($middleware, $pipeline, true));
        }

        foreach ($packageSecurity as $middleware) {
            if (array_search($middleware, $pipeline, true) < $authenticated) {
                throw new RuntimeException(__('capell-admin::message.extension_panel_security_order'));
            }
        }
    }

    /**
     * Whether a middleware entry belongs to the panel's own request stack or security, or is
     * framework infrastructure the router orders by priority, rather than the route's own middleware.
     *
     * @param  array<mixed>  $known
     */
    private function isPanelEntry(mixed $entry, array $known): bool
    {
        if (! is_string($entry)) {
            return false;
        }

        $knownClasses = array_map(static fn (mixed $middleware): string => explode(':', (string) $middleware, 2)[0], array_filter($known, is_string(...)));
        foreach ($this->router->resolveMiddleware([$entry]) as $middleware) {
            if (! is_string($middleware)) {
                return false;
            }

            $class = explode(':', $middleware, 2)[0];
            if (in_array($class, $knownClasses, true)) {
                continue;
            }

            if (! array_any($this->router->middlewarePriority, static fn (string $priority): bool => is_a($class, $priority, true))) {
                return false;
            }
        }

        return true;
    }

    private function isAuthenticator(mixed $middleware): bool
    {
        return is_string($middleware) && is_a(explode(':', $middleware, 2)[0], Authenticate::class, true);
    }

    /**
     * The route's ownership, decided from what registered it: the panel whose route group registered it
     * (Filament's panel groups name their routes for the panel and run its middleware; Capell's admin
     * routes declare their panel), public admin code, the host application's own route, or admin code
     * that no panel registered, which is refused. Registering a route for two panels is refused too.
     */
    private function ownershipAtRegistration(Route $route): PanelOwnership
    {
        $declared = AdminRouteProvenance::declared($route);
        $panels = $this->panelsNamedBy($route, asMatched: false);
        foreach ($declared['panels'] as $panel) {
            $panels[$panel] = true;
        }

        if (count($panels) > 1) {
            return PanelOwnership::refused('capell-admin::message.extension_panel_route_ambiguous');
        }

        if ($panels !== []) {
            return PanelOwnership::panel(array_key_first($panels), $route->getDomain(), $declared['guests'] || $this->isSignInPage($route));
        }

        if ($this->isPublicAction($route) || $this->isPublicRouteFile($route)) {
            return PanelOwnership::public();
        }

        if (in_array($this->actionCode($route)[0], self::ENTRYPOINT_ACTIONS, true)) {
            // The endpoint answers only on the admin entrypoint's domain, which the panel is served on.
            $domain = AdminPanelEntrypoint::domain();
            if ($domain !== null) {
                $route->domain($domain);
            }

            return PanelOwnership::panel(AdminPanelEntrypoint::PANEL, $domain, false);
        }

        if ($this->runsAdminCode($route)) {
            return PanelOwnership::refused('capell-admin::message.extension_panel_route_unowned');
        }

        return AdminRouteProvenance::isHost($route) ? PanelOwnership::host() : PanelOwnership::unclassified();
    }

    /**
     * Why the route is refused wherever it is requested, if it is: it carries no ownership (a route cache
     * built before ownership was recorded), its ownership refuses it, it names or declares a panel other than
     * the one that registered it, or it was registered on a domain its panel is not served on.
     *
     * @param  list<array{path: string, domains: list<CompiledRoute>}>|null  $matchers
     */
    private function refusal(Route $route, ?array $matchers = null): ?string
    {
        // Verifiable public framework endpoints remain public in old caches.
        if ($this->isPublicAction($route) || $this->isPublicRouteFile($route)) {
            return null;
        }

        $ownership = PanelOwnership::of($route);
        if (! $ownership instanceof PanelOwnership) {
            if ($this->isSignInPage($route)) {
                return null;
            }

            // Without a record, only a route proven to be the host's own, or public admin code, keeps serving: a
            // serialised closure cannot be inspected, and other admin code may belong to a panel.
            return RouteAction::containsSerializedClosure($route->getAction())
                || $this->panelsNamedBy($route, asMatched: true, includeUnknown: true) !== []
                || AdminRouteProvenance::declared($route)['panels'] !== []
                || $this->matchesPanelPath($route, $matchers)
                || $this->runsAdminCode($route)
                ? 'capell-admin::message.extension_panel_route_unrecorded'
                : null;
        }

        if ((in_array($ownership->kind, [RouteOwnershipEnum::Host, RouteOwnershipEnum::Unclassified], true) && $this->runsAdminCode($route))
            || ($ownership->kind === RouteOwnershipEnum::Unclassified && $this->matchesPanelPath($route, $matchers))) {
            return 'capell-admin::message.extension_panel_route_unowned';
        }

        if ($ownership->kind === RouteOwnershipEnum::Refused) {
            return $ownership->reason ?? 'capell-admin::message.extension_panel_route_unowned';
        }

        // Naming, declaring or re-domaining a route after it was registered does not move it to a panel.
        $named = array_keys($this->panelsNamedBy($route, asMatched: true));
        $registeredFor = $ownership->kind === RouteOwnershipEnum::Panel ? [(string) $ownership->panel] : [];
        if (array_diff($named, $registeredFor) !== [] || ($registeredFor !== [] && $route->getDomain() !== $ownership->domain)) {
            return 'capell-admin::message.extension_panel_route_reassigned';
        }

        if ($ownership->kind !== RouteOwnershipEnum::Panel || $ownership->panel === null) {
            return null;
        }

        $panel = $this->synchronised[$ownership->panel] ?? null;
        if (! $panel instanceof Panel) {
            // Standalone declarations, global admin plugin endpoints and Capell's own admin code depend on
            // Capell to add their security. A native Filament panel's other routes carry their own stack.
            return AdminRouteProvenance::declared($route)['panels'] === []
                && ! in_array($this->actionCode($route)[0], self::ENTRYPOINT_ACTIONS, true)
                && ! $this->runsCapellAdminCode($route)
                && (array_key_exists($ownership->panel, Filament::getPanels()) || $this->isUnavailable($ownership->panel))
                    ? null
                    : 'capell-admin::message.extension_panel_route_unowned';
        }

        return $this->withinPanelDomains($route, $this->compilePanelDomains(array_values($panel->getDomains())))
            ? null
            : 'capell-admin::message.extension_panel_route_off_domain';
    }

    /** The synchronised panel serving the route by its recorded ownership. */
    private function ownerOf(Route $route): ?Panel
    {
        $ownership = PanelOwnership::of($route);

        return $ownership?->kind === RouteOwnershipEnum::Panel && $ownership->panel !== null
            ? $this->synchronised[$ownership->panel] ?? null
            : null;
    }

    /** Report, once per process, that requests are served from a route cache that records no ownership. */
    private function reportStaleRouteCache(): void
    {
        if ($this->staleCacheReported) {
            return;
        }

        $this->staleCacheReported = true;
        Log::critical(__('capell-admin::message.route_cache_unrecorded'));
    }

    /**
     * @param  array<array-key, Route>  $routes
     * @return list<string>
     */
    private function describe(array $routes): array
    {
        return array_values(array_map(
            static fn (Route $route): string => $route->getName() ?? implode('|', $route->methods()) . ' ' . $route->uri(),
            $routes,
        ));
    }

    /**
     * The panels the route names (`filament.{panel}.`) or whose panel middleware it declares. As a route is
     * registered these come only from its route groups; as it is matched, from all it declares now.
     *
     * @return array<string, true>
     */
    private function panelsNamedBy(Route $route, bool $asMatched, bool $includeUnknown = false): array
    {
        $panels = [];
        if (preg_match('/^filament\.([^.]+)\./', (string) $route->getName(), $matches) === 1 && ! in_array($matches[1], ['exports', 'imports'], true)) {
            $panels[$matches[1]] = true;
        }

        // As a route is registered its controller is not resolved, so only the middleware its groups declare counts;
        // as it is matched, all it declares now, uncached, so middleware added since its last request counts too.
        $declared = $this->router->resolveMiddleware($asMatched ? [...$route->middleware(), ...$route->controllerMiddleware()] : array_values(array_filter((array) $route->getAction('middleware'), is_string(...))));
        foreach ($declared as $middleware) {
            if (is_string($middleware) && preg_match('/^(?:panel|' . preg_quote(SetUpPanel::class, '/') . '):(.+)$/', $middleware, $matches) === 1) {
                $panels[$matches[1]] = true;
            }
        }

        if (! $asMatched || $includeUnknown) {
            return $panels;
        }

        // A global Filament route such as `filament.exports.download` names no panel.
        return array_intersect_key($panels, Filament::getPanels() + $this->synchronised);
    }

    /** One of Filament's sign-in, password-reset or registration pages. */
    private function isSignInPage(Route $route): bool
    {
        $class = $this->actionCode($route)[0];
        if ($class === null || ! array_any(self::SIGN_IN_PAGES, static fn (string $page): bool => is_a($class, $page, true))) {
            return false;
        }

        $panels = $this->panelsNamedBy($route, asMatched: true, includeUnknown: true);

        return count($panels) === 1 && isset($this->synchronised[array_key_first($panels)]);
    }

    /** Admin code that serves every visitor, such as Livewire's own endpoints. */
    private function isPublicAction(Route $route): bool
    {
        $uses = $route->getAction('uses');
        if ($uses instanceof Closure || RouteAction::containsSerializedClosure($route->getAction())) {
            return LivewireRouteAction::closureClass($uses) !== null;
        }

        return in_array($this->actionCode($route)[0], self::PUBLIC_ACTIONS, true);
    }

    /** A closure in one of the admin plugin route files whose routes are public. */
    private function isPublicRouteFile(Route $route): bool
    {
        $file = $this->actionCode($route)[1];
        if ($file === null) {
            return false;
        }

        foreach (self::PUBLIC_ROUTE_FILES as $package => $routes) {
            $root = InstalledVersions::isInstalled($package) ? InstalledVersions::getInstallPath($package) : null;
            $path = $root === null ? false : realpath($root . DIRECTORY_SEPARATOR . $routes);
            if ($path !== false && $path === $file) {
                return true;
            }
        }

        return false;
    }

    /** The Filament page class served directly or through Livewire's route macro. */
    private function filamentPage(Route $route): ?string
    {
        $class = $this->actionCode($route)[0];
        if ($class === LivewirePageController::class) {
            $component = $route->getAction('livewire_component');
            $class = LivewireRouteComponentClass::of($component);
        }

        return $class !== null && is_subclass_of($class, BasePage::class) ? $class : null;
    }

    /** The action is shipped in an admin package's source or route files, or is a Filament page. */
    private function runsAdminCode(Route $route): bool
    {
        [$class, $file] = $this->actionSource($route);
        if ($class !== null && is_subclass_of($class, BasePage::class)) {
            return true;
        }

        return $file !== null && array_any(array_keys($this->adminRoots()), static fn (string $root): bool => str_starts_with($file, $root));
    }

    /**
     * The action is shipped in Capell's own admin source or route files. Filament, Livewire, other admin
     * plugins and the host's own pages are not Capell code: a panel without the Capell plugin keeps serving
     * them under its own stack, but cannot serve Capell's admin code without Capell's package security.
     */
    private function runsCapellAdminCode(Route $route): bool
    {
        $file = $this->actionSource($route)[1];
        if ($file === null) {
            return false;
        }

        foreach ($this->adminRoots() as $root => $prefix) {
            $capellRoot = $prefix !== null && str_starts_with($prefix, self::CAPELL_ADMIN_NAMESPACE);
            $capellRoutes = $prefix !== null && $capellRoot ? dirname($root) . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR : null;
            if (($capellRoot && str_starts_with($file, $root)) || ($capellRoutes !== null && str_starts_with($file, $capellRoutes))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class the route runs, resolving Livewire's page macro to its component, and the file that holds it.
     *
     * @return array{0: class-string|null, 1: string|null}
     */
    private function actionSource(Route $route): array
    {
        [$class, $file] = $this->actionCode($route);
        if ($class === LivewirePageController::class) {
            $component = $route->getAction('livewire_component');
            // The macro adds its component after Route::get() has recorded ownership. At that point
            // classification waits for the finished action; it never exempts a panel-owned route.
            if ($component === null) {
                return [null, null];
            }

            $class = LivewireRouteComponentClass::of($component);
        }

        if ($class === null || ! class_exists($class)) {
            return [null, $file];
        }

        if ($file === null) {
            $file = new ReflectionClass($class)->getFileName();
            $file = $file === false ? null : (realpath($file) ?: $file);
        }

        return [$class, $file];
    }

    /** Older caches inferred host ownership, so only retain it with an explicit declaration. */
    private function demoteLegacyHost(Route $route): void
    {
        if (PanelOwnership::of($route)?->kind === RouteOwnershipEnum::Host && ! AdminRouteProvenance::isHost($route)) {
            PanelOwnership::unclassified()->recordOn($route);
        }
    }

    /**
     * A refusal describes the finished route now, never its incomplete registration-time shape.
     * Security reconciliation is retried too, so an earlier failure cannot survive a corrected route.
     *
     * @param  list<array{path: string, domains: list<CompiledRoute>}>|null  $matchers
     */
    private function refreshRefusal(Route $route, ?array $matchers = null): void
    {
        if (! PanelOwnership::of($route) instanceof PanelOwnership) {
            $this->reportStaleRouteCache();
        }

        if (isset($this->refusedRoutes[$route])) {
            $route->computedMiddleware = null;
        }

        unset($this->refusedRoutes[$route]);
        try {
            $reason = $this->refusal($route, $matchers);
            if ($reason !== null) {
                $this->refuse($route, new RuntimeException(__($reason)));
            }
        } catch (Throwable $throwable) {
            $this->refuse($route, $throwable);
        }
    }

    /** @param list<array{path: string, domains: list<CompiledRoute>}>|null $matchers */
    private function matchesPanelPath(Route $route, ?array $matchers = null): bool
    {
        $uri = trim($route->uri(), '/');
        foreach ($matchers ?? $this->panelPathMatchers() as ['path' => $path, 'domains' => $domains]) {
            if (! $this->overlapsPanelDomains($route, $domains)) {
                continue;
            }

            if ($path !== '' && ($uri === $path || str_starts_with($uri, $path . '/'))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{path: string, domains: list<CompiledRoute>}> */
    private function panelPathMatchers(): array
    {
        $domain = AdminPanelEntrypoint::domain();
        $matchers = [['path' => AdminPanelEntrypoint::path(), 'domains' => $this->compilePanelDomains($domain === null ? [] : [$domain])]];
        foreach ($this->synchronised as $panel) {
            $matchers[] = ['path' => trim($panel->getPath(), '/'), 'domains' => $this->compilePanelDomains(array_values($panel->getDomains()))];
        }

        return $matchers;
    }

    /**
     * @param  list<string>  $domains
     * @return list<CompiledRoute>
     */
    private function compilePanelDomains(array $domains): array
    {
        return array_map(fn (string $domain): CompiledRoute => new SymfonyRoute('/', requirements: $this->router->getPatterns(), host: $domain)->compile(), $domains);
    }

    /** @param list<CompiledRoute> $domains */
    private function overlapsPanelDomains(Route $route, array $domains): bool
    {
        if ($domains === [] || ! $route->getDomain()) {
            return true;
        }

        // Compile the final route afresh: Laravel may have cached a compilation before a fluent change.
        $host = $route->toSymfonyRoute()->compile();

        return array_any($domains, fn (CompiledRoute $panel): bool => $this->hostPatternsOverlap($host, $panel));
    }

    /**
     * A panel-owned route must stay within its panel's hosts, not merely intersect them.
     *
     * @param  list<CompiledRoute>  $domains
     */
    private function withinPanelDomains(Route $route, array $domains): bool
    {
        if ($domains === []) {
            return true;
        }

        if (! $route->getDomain()) {
            return false;
        }

        $host = $route->toSymfonyRoute()->compile();

        return array_any($domains, fn (CompiledRoute $panel): bool => $host->getHostRegex() === $panel->getHostRegex()
            || ($host->getHostVariables() === [] && $this->hostPatternsOverlap($host, $panel)));
    }

    /** Use the same compiled host constraints as Laravel; uncertain pattern intersections fail closed. */
    private function hostPatternsOverlap(CompiledRoute $left, CompiledRoute $right): bool
    {
        if (! $left->getHostRegex() || ! $right->getHostRegex()) {
            return true;
        }

        if ($left->getHostVariables() === []) {
            return preg_match($right->getHostRegex(), (string) $left->getHostTokens()[0][1]) === 1;
        }

        if ($right->getHostVariables() === []) {
            return preg_match($left->getHostRegex(), (string) $right->getHostTokens()[0][1]) === 1;
        }

        // Symfony stores host tokens in reverse order. Conflicting fixed edges prove disjointness;
        // arbitrary parameter requirements cannot safely be treated as disjoint.
        $leftTokens = $left->getHostTokens();
        $rightTokens = $right->getHostTokens();
        foreach ([true, false] as $suffix) {
            $a = $suffix ? $leftTokens[0] : $leftTokens[array_key_last($leftTokens)];
            $b = $suffix ? $rightTokens[0] : $rightTokens[array_key_last($rightTokens)];
            if ($a[0] !== 'text') {
                continue;
            }

            if ($b[0] !== 'text') {
                continue;
            }

            $length = min(strlen((string) $a[1]), strlen((string) $b[1]));
            $a = strtolower($suffix ? substr((string) $a[1], -$length) : substr((string) $a[1], 0, $length));
            $b = strtolower($suffix ? substr((string) $b[1], -$length) : substr((string) $b[1], 0, $length));
            if ($a !== $b) {
                return false;
            }
        }

        return true;
    }

    /**
     * The controller class an action runs, or the file declaring its closure together with the admin
     * package class that file holds, if any.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function actionCode(Route $route): array
    {
        $uses = $route->getAction('uses');
        // A closure from the route cache stays serialised until the route runs, and is never unserialised to be read.
        if (RouteAction::containsSerializedClosure($route->getAction())) {
            return [null, null];
        }

        if ($uses instanceof Closure) {
            $file = new ReflectionFunction($uses)->getFileName();
            if ($file === false) {
                return [null, null];
            }

            $file = realpath($file) ?: $file;
            foreach ($this->adminRoots() as $root => $prefix) {
                if ($prefix !== null && str_starts_with($file, $root) && str_ends_with($file, '.php')) {
                    return [$prefix . str_replace(DIRECTORY_SEPARATOR, '\\', substr($file, strlen($root), -4)), $file];
                }
            }

            return [null, $file];
        }

        if (is_string($uses) && $uses !== '') {
            $class = Str::parseCallback($uses)[0];

            return [is_string($class) ? ltrim($class, '\\') : null, null];
        }

        return [null, null];
    }

    /**
     * The source and route directories of the admin packages, found through Composer's PSR-4 map,
     * each with the namespace its source directory holds. Their test suites are not admin code.
     *
     * @return array<string, string|null>
     */
    private function adminRoots(): array
    {
        if ($this->adminRoots !== null) {
            return $this->adminRoots;
        }

        $namespaces = self::ADMIN_NAMESPACES;
        $roots = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                if (! array_any($namespaces, static fn (string $namespace): bool => str_starts_with($prefix, $namespace))) {
                    continue;
                }

                foreach ($directories as $directory) {
                    $path = realpath($directory);
                    // A package's route files sit beside its source directory.
                    if ($path !== false && basename($path) === 'src') {
                        $roots[$path . DIRECTORY_SEPARATOR] = $prefix;
                        $roots[dirname($path) . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR] ??= null;
                    }
                }
            }
        }

        return $this->adminRoots = $roots;
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
