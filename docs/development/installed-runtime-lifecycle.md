# Installed runtime lifecycle

Put installed-only application wiring in `protected function bootInstalledRuntime(): void` on `AbstractPackageServiceProvider`. Core activates each provider once per application bootstrap, after its required packages, when it is installed and enabled. The same lifecycle runs after `InstallPackageAction` and `EnablePackageAction`, including providers loaded before installation.

```php
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Override;
use Spatie\LaravelPackageTools\Package;

final class ExampleServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'example';
    public static string $packageName = 'vendor/example';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(self::$name)->hasConfigFile();
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'example');
    }
}
```

Ordinary Laravel providers, including child Admin and Frontend providers, use `RegistersInstalledRuntime`. Enrol the provider from `register()`, with the owning Composer package name and its runtime bucket:

```php
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class ExampleFrontendServiceProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('vendor/example', 'frontend');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'example');
    }
}
```

The trait's abstract method is not a parent-class override: do not put `#[Override]` on its implementation. Use the attribute when overriding the concrete hook inherited from `AbstractPackageServiceProvider`.

## Eligibility, ordering and failures

`InstalledRuntimeLifecycle` is an application singleton. It tracks provider class identity, not a request or job. It checks enabled state before activation; uninstalled, disabled, failed and quarantined packages cannot consume their activation. Package discovery cannot activate runtime. Admin adapters stay inactive in the public runtime role. Installation refresh uses `CapellPackageLoader`'s provider and bucket selection and contribution receipt contexts.

Providers are enrolled before application boot finishes. Activation follows `PackageData::getRequirements()` recursively; an unavailable dependency prevents activation, and a dependency cycle raises an exception. Main and preloaded child providers use the same guard. Re-entry during an activation and repeated successful refreshes do not repeat its wiring. Registries frozen at boot are opened through `PackageSurfaceRegistrar::duringPackageInstallation()`.

An exception propagates and leaves that provider retryable. Registration is **not transactional**: Laravel does not roll back listeners, tags or routes already added before an exception. Validate prerequisites before mutating registries. If a hook can fail after applying part of its wiring, make that portion retry-safe or discard the application and retry in a fresh process. The installation action records failure; restoring eligibility is required before retrying activation.

Keep configuration, install commands, metadata and pre-install listeners in their existing early phase. Never capture a request, user, tenant, model instance or job in runtime registration. Resolve those at execution time.

## Migration and compatibility

Nothing is deprecated by this change. Existing `bootInstalledPackage(): self`, `bootPackage(): self`, Spatie `packageRegistered()` / `packageBooted()`, and provider booted callbacks retain their existing behaviour for providers that do not adopt the new hook. Legacy callbacks may run repeatedly during installation; existing private guards remain necessary until migration. Enabling an already loaded package does not introduce additional legacy callback replays; the new lifecycle is refreshed independently.

Overriding `bootInstalledRuntime()` opts an abstract package provider into the new lifecycle and suppresses its **legacy `bootInstalledPackage()` callback**. Core never calls both installed hooks automatically. It still calls `bootPackage()` and Spatie's hooks as before. Move every installed registration from those other methods and `$this->app->booted()` callbacks into the new hook; Core cannot infer which arbitrary callback bodies are safe to replay or suppress.

For each provider:

1. Keep discovery, configuration and install plumbing in their existing phase.
2. Move installed registrations from `register()`, `boot()`, `packageRegistered()`, `packageBooted()` and application callbacks into `bootInstalledRuntime()`.
3. Migrate child providers separately, using the trait and their actual bucket.
4. Remove private once-only flags and replay callbacks only after all installed wiring has moved. Retain independent conditions, such as theme availability or optional integrations.
5. Raise the package's minimum Core version to the release that introduces this API before shipping the migration. Until that release is known, depend on the reviewed Core commit in the development checkout; do not publish an invented version constraint.

## Surfaces and remaining boundaries

| Surface | In-process behaviour and verification |
| --- | --- |
| Event listeners, schedules and tags | Hook executes once. Existing `Schedule` instances receive `registerSchedule()` callbacks immediately. The parity test compares listener counts, scheduled events and contributor lists without removing duplicates. |
| Policies and gates | Registrations update the current Gate service. The parity test checks both maps. Do not retain request-specific `Gate::forUser()` clones across jobs. |
| Livewire, views and Blade | Their existing registries accept late definitions. The parity test checks exact aliases and view hints. |
| Manifest contributions | Declarations remain an index; they do not execute registration. The fixture resolves its declaration, health-check registry and tagged consumer before activation, then requires the declared class in the actual health-check registry. |
| Tagged registries resolved before installation | `TaggedProviderRegistry` remains live even if the tag was initially empty. Health, publication-readiness, SiteSpec and project-build registries consume appended entries without masking duplicate registrations. Dedicated late-contributor tests cover each. |
| Panel extenders and middleware | Each tagged extender entry applies once per panel; duplicate registrations remain observable. Synchronisation also updates middleware on existing named panel routes and clears their computed middleware. Real authenticated HTTP tests cover ordinary and compiled route collections. Retry retains the last synchronised middleware snapshot. If existing authenticated routes cannot be identified, refresh throws instead of silently leaving them unprotected. Exceptions propagate. |
| Panel pages, resources and widgets | Existing panel membership is synchronised; new page/widget entries are appended only once. Panel registration updates Livewire components and persistent middleware. See the route and component-cache boundary below. |
| Ordinary routes | Direct route registration inside the hook works in the current router. Fresh/late parity checks the named route. A cached collection can accept new routes, but `loadRoutesFrom()` deliberately skips its file while Laravel reports cached routes. Cache rebuilding remains a deployment operation. |
| Database platform contributors | This registry is deliberately operation-scoped. A current operation retains its existing driver registry; subsequent operations pick up tags. `DatabasePlatformRegistryTest` covers that boundary. Drivers needed to install a package belong in lifecycle-safe bootstrap plumbing. |

**Deferred: new Filament page/resource route topology and persisted route/component caches.** Panel membership is not proof that a new page URL is routable. Filament constructs routes using tenancy, domains, clusters and page/resource configurations, and a cached panel may reject late component additions. Replaying the vendor's entire route file or hand-copying its route builder would introduce a second lifecycle with different compatibility risks. Existing route middleware is refreshed and fails closed; new page routing and component-cache reconciliation still require a separate design and consumer proof.

The options are (a) a supported Filament adapter that rebuilds only the affected route/component topology and invalidates the corresponding persisted caches, or (b) completing activation at a fresh-application boundary after cache refresh. Until that decision lands, use the existing runtime-refresh/deployment flow and a fresh application before serving newly installed pages. Do not claim cached-route or new-page parity from the registration contract alone.

## Disable, uninstall and retained processes

Disabling or uninstalling does not reset a successful activation. Re-enabling in the same application therefore cannot duplicate registrations. Core changes package state, clears its existing package/component caches where the action already does so, and signals queue workers. Laravel has no general reversible registration transaction: listeners, scheduled callbacks, routes, Gate clones and third-party singletons already held by consumers remain present until the application is replaced.

Put execution-time eligibility checks around callbacks that can run after deactivation, especially scheduled tasks and event listeners. Package-owned caches and consumers need their own supported invalidation path. Deactivation must not be described as universal deregistration.

Completed install, enable, disable and uninstall actions write Laravel's shared `illuminate:queue:restart` timestamp, using the same semantics as `queue:restart`. `RunRuntimeRefreshAction` also runs the `queue:restart` stage, including when an earlier independent refresh stage failed. Workers finish their current job before stopping; use a supervisor to start replacements. The cache store and prefix must be shared with those workers. An array cache only signals the current application and is suitable for tests, not worker propagation.

Octane needs an explicit `php artisan octane:reload` after the lifecycle change and cache refresh. Reload every serving instance. Queue restart does not reload Octane or `schedule:work`. Restart retained scheduler processes through their process manager as well. Never reset `InstalledRuntimeLifecycle` from `FlushResettableState`: an Octane sandbox inherits already registered wiring. A sandbox cannot activate inherited providers whose container still belongs to the original application: Core throws before their hook runs and leaves activation retryable. Install through the deployment/CLI application, then reload Octane. The lifecycle test verifies this refusal, inherited successful ownership and that independent applications have independent guards. This is not a live Octane server test.

## Reusable package contract

`Capell\Core\Testing\Contracts\InstalledRuntimeContract::assertParity()` accepts a main provider class, an isolated boot factory, a surface snapshot, real installation and refresh callbacks, and mandatory absence/declaration assertions. It compares fresh installed boot with uninstalled boot followed by installation and repeated refresh, and verifies that installation retained the preloaded main provider instance. The factory must explicitly preload Composer-discovered main providers even when the manifest's metadata bucket is empty.

Iterate the packages repository's manifest-derived provider list. Preserve occurrences and ordering in snapshot arrays: no `unique()`, sets or subset assertions. Supply declared-surface checks so two equally incomplete snapshots cannot pass. Include preloaded children and consumers resolved before installation. `InstalledRuntimeParityTest` is the executable example; action integration tests separately cover persisted installation state, migrations, failure and frozen registries.

The [stage 2 provider worklist](installed-runtime-stage-two.md) records the audited companion sources and source-reference differences.

Contract tests need source-local autoload mappings and an isolated application skeleton. A shared Testbench manifest can carry another checkout's absolute package paths even after it is copied. Discard only the copied manifest in an owned skeleton and rediscover it there; never clear the shared skeleton to repair one run. Architecture presets additionally need an independent vendor tree: their real-path filtering can classify symlinked third-party packages as project namespaces.
