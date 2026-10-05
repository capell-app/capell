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

Providers enrol during registration. The loader batches enrolment before activation, including when the application has already booted. Required manifest providers must be enrolled or have completed ordinary legacy loading before a dependent can activate. An adopting Composer-discovered main provider in `PackageData::serviceProviderClass` must also enrol, even when all manifest provider buckets are empty; a non-adopting main provider retains its legacy behaviour. Bootstrap refresh callbacks are coalesced; replaying a completed provider callback retains no new application callback and does not rescan active providers. Before Filament constructs a panel, Admin activates enrolled providers so installed pages exist when routes are built. Activation follows `PackageData::getRequirements()` recursively; an unavailable dependency prevents activation, and a dependency cycle raises an exception. Main and preloaded child providers use the same guard. Re-entry during an activation and repeated successful refreshes do not repeat its wiring. Registries frozen at boot are opened through `PackageSurfaceRegistrar::duringPackageInstallation()`.

**Failure scope.** A failed installed-runtime hook cannot be retried in the same application: completed registrations and partial wiring are never replayed. Core denies admin panels because an opaque hook may have only partly installed their security wiring; a panel-only extender or refresh failure denies only that panel, including existing Livewire snapshots and a caught current admin response. Unrelated public HTTP, health/readiness endpoints, console commands, queue jobs, scheduler callbacks and other panels after a panel-only failure keep running; there is no global HTTP middleware.

The panel guard is route middleware. A route-matched listener checks the `filament.` name prefix without resolving services on public routes; unnamed routes receive the guard through panel middleware and synchronisation. Hook-owned routes observed during registration are denied if their package fails, and enabled-state consumers see that package as unavailable only in the failed application. An ordinary public request incurs no lifecycle queries or lifecycle container resolution.

Bootstrap contains recorded hook and panel exceptions so a failed provider cannot prevent Laravel from serving its health route or executing a command. Explicit activation operations for a failed package or bundle still throw before side effects instead of reporting success. Targeted install, enable and refresh of unrelated packages remain available; bootstrap continues independent package activation after recording a failed hook. The original exception text and trace are logged once with package, provider, bucket, step and panel context; the existing Doctor report includes an `installed-runtime` check with recovery instructions. The stored diagnostic contains strings, not exception objects or job traces. Install failures retain the existing persisted package failure record as well.

Lifecycle panel synchronisation defers panels that were already denied before the operation, without replaying their wiring or clearing their denial. It logs the deferral and sends an Admin warning notification with fresh-application recovery instructions. New surfaces on those panels wait for a fresh application; unrelated package state can still become enabled. A new panel failure during a package refresh event still throws and records the triggering package as failed in the application, without logging the same exception twice. That package's install, enable and refresh cannot report success on retry. Direct `InstalledPanelRuntime` activation of a denied panel still refuses replay. The same synchronisation boundary applies to Extensions table refresh after enable, disable or uninstall, and to package refresh events from bundle and Marketplace installation.

PHP-FPM creates a fresh Laravel application for the next request; the in-memory denial belongs only to the failed application. An inherited Octane sandbox retains that denial, so repair the cause and run `php artisan octane:reload` on each serving instance. Restart retained queue and scheduler processes through their existing process managers. Tests cover independent applications and inherited containers; these are not live FPM/Octane reload tests.

Registration is **not transactional**. Arbitrary listeners, scheduled callbacks, gates, shared singleton mutations, or routes registered outside the observed hook cannot be universally revoked or rolled back. Such consumers must check package eligibility at execution time. Hooks that mutate global public middleware or bypass eligibility after partial registration are unsupported: supporting those safely requires declarative, package-owned registrations with execution-time admission (or isolated activation in a disposable application before publishing). Core does not impose a wider outage to compensate for that limitation.

Keep configuration, install commands, metadata and pre-install listeners in their existing early phase. Never capture a request, user, tenant, model instance or job in runtime registration. Resolve those at execution time.

## Migration and compatibility

Nothing is deprecated by this change. Existing `bootInstalledPackage(): self`, `bootPackage(): self`, Spatie `packageRegistered()` / `packageBooted()`, and provider booted callbacks retain their existing behaviour for providers that do not adopt the new hook. Legacy callbacks may run repeatedly during installation; existing private guards remain necessary until migration. Enable does not introduce legacy callback replays or load previously unloaded non-adopting providers. It loads missing adopters and refreshes their guarded hooks. Installation retains its existing legacy loading behaviour.

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
| Panel extenders and middleware | Each tagged occurrence applies once. Existing routes are identified by panel middleware or name, including unnamed and compiled routes. Authentication groups are resolved, authentication and tenant middleware are synchronised, and exclusions are checked. Ambiguous security coverage, extender failures and panel registration failures deny the affected panel until a fresh application. No replay of its partially completed extenders is allowed. |
| Livewire security | Core automatically persists resolved extender-added middleware classes and the authentication/tenant chain. Livewire replays the actual updated route pipeline, including its order, parameters and exclusions. Real HTTP snapshot replay tests protect the default `authMiddleware()` API, without requiring consumers to remember `isPersistent: true`. An extender may implement `DeclaresFullPageOnlyMiddleware::fullPageOnlyMiddleware()` to explicitly exclude named middleware classes from Core's automatic persistence. Use this only for full-page behaviour, never authentication or authorisation. Filament records omitted and explicit `isPersistent: false` identically, so that flag alone cannot opt out of Core's safe default; the declaration is required. Do not explicitly persist the same class elsewhere: Livewire's explicit class list is global across panels. |
| Panel pages, resources and widgets | Installed contributions are consumed before fresh panel route construction. Existing panels defer new component membership until a fresh application; they do not offer unroutable navigation or register new Livewire page aliases. Extenders that mutate existing panel topology require a fresh application and fail closed. |
| Ordinary routes | Direct route registration inside the hook works in the current router. Fresh/late parity checks the named route. A cached collection can accept new routes, but `loadRoutesFrom()` deliberately skips its file while Laravel reports cached routes. The shared activation boundary clears persisted route/configuration caches through the existing runtime-refresh stages and verifies removal. Rebuilding remains a deployment operation. |
| Database platform contributors | This registry is deliberately operation-scoped. A current operation retains its existing driver registry; subsequent operations pick up tags. `DatabasePlatformRegistryTest` covers that boundary. Drivers needed to install a package belong in lifecycle-safe bootstrap plumbing. |

**Fresh-application boundary for panel topology.** Core does not replay Filament's route builder in an existing application. Every install, enable and explicit package refresh clears existing Laravel route/configuration caches through `RefreshRouteCacheAction` and `RefreshConfigurationCacheAction` in clear mode, verifies their removal, and clears package/component caches. Failure is an incomplete activation exception through the existing CLI, Action and Marketplace failure channels, never plain success. Rebuilding a cache from the old panel topology would retain the original bug.

The Admin install action additionally verifies every panel component cache, then sends a full-page Livewire redirect (`navigate: false`) to Extensions. The next ordinary FPM request constructs routes and navigation from installed contributions. Single-node CLI and queued Marketplace installs cross the same persisted-cache boundary without relying on this UI action. Marketplace's existing operation timeline still reports manual refresh for multi-node deployments and worker reload requirements for Octane. Cache clearing on one node cannot refresh other nodes or an already booted serving application.

Octane request sandboxes cannot install or enable packages: ownership is checked before bundle members, state writes, migrations or install Actions. Use the owning deployment/CLI application, refresh caches and reload retained workers before reloading the UI. A redirect alone cannot replace an Octane worker. CLI progress reports explicitly identify pending retained/multi-node activation, and callers must perform those deployment steps; the current void install/enable Action contracts do not attest worker convergence. Rebuilding existing panel route/component topology and proving remote worker convergence remain unsupported.

## Disable, uninstall and retained processes

Disabling or uninstalling does not reset a successful activation. Subsequent unchanged panel synchronisation is permitted in inherited applications; ownership is checked only before applying a new extender, not after completed cleanup. Re-enabling in the same application therefore cannot duplicate registrations. Core changes package state, clears its existing package/component caches where the action already does so, and signals queue workers. Laravel has no general reversible registration transaction: listeners, scheduled callbacks, routes, Gate clones and third-party singletons already held by consumers remain present until the application is replaced.

Put execution-time eligibility checks around callbacks that can run after deactivation, especially scheduled tasks and event listeners. Package-owned caches and consumers need their own supported invalidation path. Deactivation must not be described as universal deregistration.

Completed install, enable, disable and uninstall actions write Laravel's shared `illuminate:queue:restart` timestamp, using the same semantics as `queue:restart`. A bundle install writes once, after the entire outer operation succeeds; failed bundles do not signal success. Boot, request handling and runtime-hook refresh never emit this signal. `RunRuntimeRefreshAction` also runs the `queue:restart` stage, including when an earlier independent refresh stage failed. Workers finish their current job before stopping; use a supervisor to start replacements. The cache store and prefix must be shared with those workers. An array cache only signals the current application and is suitable for tests, not worker propagation.

Octane needs an explicit `php artisan octane:reload` after the lifecycle change and cache refresh. Reload every serving instance. Queue restart does not reload Octane or `schedule:work`. Restart retained scheduler processes through their process manager as well. Never reset `InstalledRuntimeLifecycle` from `FlushResettableState`: an Octane sandbox inherits already registered wiring. A sandbox cannot activate inherited providers whose container still belongs to the original application: Core refuses install and enable before any side effects, and rejects direct activation before hooks run. A rejected sandbox admission does not poison its owning application. Install through the deployment/CLI application, then reload Octane. The lifecycle test verifies this refusal, inherited successful ownership and that independent applications have independent guards. This is not a live Octane server test.

## Reusable package contract

`Capell\Core\Testing\Contracts\InstalledRuntimeContract::assertParity()` accepts a main provider class, an isolated boot factory, a surface snapshot, real installation and refresh callbacks, and mandatory absence/declaration assertions. It compares fresh installed boot with uninstalled boot followed by installation and repeated refresh, and verifies that installation retained the preloaded main provider instance. The factory must explicitly preload Composer-discovered main providers even when the manifest's metadata bucket is empty.

Iterate the packages repository's manifest-derived provider list. Preserve occurrences and ordering in snapshot arrays: no `unique()`, sets or subset assertions. Supply declared-surface checks so two equally incomplete snapshots cannot pass. Include preloaded children and consumers resolved before installation. `InstalledRuntimeParityTest` is the executable example; action integration tests separately cover persisted installation state, migrations, failure and frozen registries.

The [stage 2 provider worklist](installed-runtime-stage-two.md) records the audited companion sources and source-reference differences.

Contract tests need source-local autoload mappings and an isolated application skeleton. A shared Testbench manifest can carry another checkout's absolute package paths even after it is copied. Discard only the copied manifest in an owned skeleton and rediscover it there; never clear the shared skeleton to repair one run. Architecture presets additionally need an independent vendor tree: their real-path filtering can classify symlinked third-party packages as project namespaces.

For host verification with linked dependencies, set a unique `UNIQUE_TEST_TOKEN` for **every** Pest process, including serial runs. Without it, Testbench uses the shared vendor skeleton; focused commands in separate processes can then overwrite one another's provider/cache fixtures. The token creates an owned skeleton under `var/testbench-skeletons/` and cleans it on shutdown.
