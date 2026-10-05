<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Events\InstalledRuntimeRefreshed;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Http\Middleware\EnsureInstalledRuntimeAvailable;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use WeakMap;
use WeakReference;

/** Application-bootstrap state: never reset this service at request/job boundaries. */
final class InstalledRuntimeLifecycle
{
    /** @var array<class-string, array{package: string, bucket: string, activate: Closure(): void}> */
    private array $providers = [];

    /** @var array<class-string, 'activating'|'active'|'failed'> */
    private array $states = [];

    /** @var array<string, list<Route>> */
    private array $packageRoutes = [];

    private bool $refreshing = false;

    private bool $unavailable = false;

    /** @var array<string, array{package: string, provider: string, bucket: string, step: string, panel: ?string, message: string}> */
    private array $failures = [];

    private bool $bootRefreshScheduled = false;

    /** @var array<class-string, true> */
    private array $bootedProviders = [];

    private int $registrationDepth = 0;

    /** @var array<string, array<class-string, true>> */
    private array $pending = [];

    /** @var WeakReference<Throwable>|null */
    private ?WeakReference $lastFailure = null;

    /** @var array<string, true> */
    private array $activatedPackages = [];

    /** @var WeakMap<Throwable, true> */
    private WeakMap $reported;

    public function __construct(private readonly Application $app)
    {
        $this->reported = new WeakMap;
    }

    /** @param class-string $provider */
    public static function adopts(string $provider): bool
    {
        return in_array(RegistersInstalledRuntime::class, class_uses_recursive($provider), true)
            && new ReflectionMethod($provider, 'bootInstalledRuntime')->getDeclaringClass()->getName() !== AbstractPackageServiceProvider::class;
    }

    public function wasReported(Throwable $exception): bool
    {
        return isset($this->reported[$exception]);
    }

    /**
     * @param  class-string  $provider
     * @param  Closure(): void  $activate
     */
    public function register(string $provider, string $package, string $bucket, Closure $activate): void
    {
        if (isset($this->providers[$provider])) {
            return;
        }

        $this->providers[$provider] = ['package' => $package, 'bucket' => $bucket, 'activate' => $activate];
        $this->pending[$package][$provider] = true;
    }

    public function assertCanActivate(?string $package = null): void
    {
        if (Container::getInstance() !== $this->app) {
            throw new RuntimeException(__('capell::runtime-refresh.owning_application_required'));
        }

        if ($package !== null) {
            $this->assertPackageAvailable($package);
        } elseif ($this->unavailable) {
            throw new RuntimeException(__('capell::runtime-refresh.failed_application'));
        }
    }

    public function recordFailure(Throwable $exception, string $package, string $provider, string $bucket, string $step, ?string $panel = null): void
    {
        $this->reported[$exception] = true;
        $key = $panel === null ? $package : 'panel:' . $panel;
        if (isset($this->failures[$key])) {
            return;
        }

        $this->failures[$key] = ['package' => $package, 'provider' => $provider, 'bucket' => $bucket, 'step' => $step, 'panel' => $panel, 'message' => $exception->getMessage()];
        Log::error('Installed runtime registration failed.', [...$this->failures[$key], 'exception' => (string) $exception]);
    }

    /** @return list<array{package: string, provider: string, bucket: string, step: string, panel: ?string, message: string}> */
    public function failures(): array
    {
        return array_values($this->failures);
    }

    public function packageFailed(string $package): bool
    {
        return isset($this->failures[$package]);
    }

    /** Bootstrap failures deny protected surfaces, never the application's entry points. */
    public function refreshForBootstrap(?string $package = null): void
    {
        try {
            $this->refresh($package);
        } catch (Throwable $throwable) {
            throw_if(! $this->failed($throwable) && ! $this->unavailable, $throwable);
        }
    }

    public function invalidate(): void
    {
        $this->unavailable = true;
    }

    public function isUnavailable(): bool
    {
        return $this->unavailable;
    }

    /** @param class-string $provider */
    public function providerBooted(string $provider): void
    {
        if (isset($this->bootedProviders[$provider])) {
            return;
        }

        $this->bootedProviders[$provider] = true;
        if ($this->app->isBooted()) {
            $this->refreshForBootstrap($this->providers[$provider]['package']);

            return;
        }

        if (! $this->bootRefreshScheduled) {
            $this->bootRefreshScheduled = true;
            $this->app->booted(function (): void {
                $this->refreshForBootstrap();
            });
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function duringProviderRegistration(Closure $callback, ?string $package = null): mixed
    {
        $this->registrationDepth++;
        try {
            $result = $callback();
        } finally {
            $this->registrationDepth--;
        }

        if ($this->app->isBooted()) {
            $this->refresh($package);
        }

        return $result;
    }

    public function refresh(?string $package = null): void
    {
        if ($this->refreshing || $this->registrationDepth > 0 || $this->discovering()) {
            return;
        }

        if ($this->unavailable && $package === null) {
            throw new RuntimeException(__('capell::runtime-refresh.failed_application'));
        }

        if ($this->pending === [] && $this->activatedPackages === []) {
            return;
        }

        $this->assertCanActivate($package);
        $this->refreshing = true;

        try {
            $this->app->make(PackageSurfaceRegistrar::class)->duringPackageInstallation(function (): void {
                $failure = null;
                do {
                    $known = count($this->providers);
                    $completed = [];
                    foreach (array_keys($this->pending) as $package) {
                        try {
                            $this->activatePackage($package, [], $completed);
                        } catch (Throwable $exception) {
                            $failure ??= $exception;
                        }
                    }
                } while (count($this->providers) !== $known);

                $activated = array_keys($this->activatedPackages);
                foreach ($activated as $package) {
                    if ($this->app->isBooted()) {
                        $this->app->make(Dispatcher::class)->dispatch(new InstalledRuntimeRefreshed(CapellCore::getPackage($package)));
                    }

                    unset($this->activatedPackages[$package]);
                }

                if (! $failure instanceof Throwable) {
                    return;
                }

                throw $failure;
            });
        } catch (Throwable $throwable) {
            // Preserve identity for the loader without retaining a failed job's trace.
            $this->lastFailure = WeakReference::create($throwable);

            throw $throwable;
        } finally {
            $this->refreshing = false;
        }
    }

    public function failed(Throwable $throwable): bool
    {
        return $this->lastFailure?->get() === $throwable;
    }

    /** @param list<string> $checked */
    private function assertPackageAvailable(string $package, array $checked = []): void
    {
        if ($this->packageFailed($package)) {
            throw new RuntimeException(__('capell::runtime-refresh.failed_application'));
        }

        if (in_array($package, $checked, true) || ! CapellCore::hasPackage($package)) {
            return;
        }

        $data = CapellCore::getPackage($package);
        if ($data->getKind() === 'bundle') {
            foreach ($data->getRequirements() as $member) {
                $this->assertPackageAvailable($member, [...$checked, $package]);
            }
        }
    }

    /**
     * @param  list<string>  $ancestors
     * @param  array<string, bool>  $completed
     */
    private function activatePackage(string $package, array $ancestors, array &$completed): bool
    {
        if (array_key_exists($package, $completed)) {
            return $completed[$package];
        }

        if (! CapellCore::isPackageEnabled($package)) {
            return $completed[$package] = false;
        }

        if (in_array($package, $ancestors, true)) {
            throw new RuntimeException('Circular installed-runtime dependency: ' . implode(' -> ', [...$ancestors, $package]));
        }

        $data = CapellCore::getPackage($package);
        // Composer discovery can preload a main provider absent from every bucket.
        $main = $data->serviceProviderClass;
        if ($ancestors !== [] && $main !== null && self::adopts($main) && ! isset($this->providers[$main])) {
            return $completed[$package] = false;
        }

        foreach (['auth', 'runtime', 'admin', 'frontend'] as $bucket) {
            if (! $this->selected($bucket)) {
                continue;
            }

            foreach ($data->getProviderClasses($bucket) as $provider) {
                if (! isset($this->providers[$provider]) && ! $this->app->providerIsLoaded($provider)) {
                    return $completed[$package] = false;
                }
            }
        }

        foreach ($data->getRequirements() as $requirement) {
            if (! $this->activatePackage($requirement, [...$ancestors, $package], $completed)) {
                return $completed[$package] = false;
            }
        }

        do {
            $known = count($this->providers);
            foreach (array_keys($this->pending[$package] ?? []) as $provider) {
                $registration = $this->providers[$provider];

                if (in_array($this->states[$provider] ?? null, ['activating', 'active'], true)) {
                    continue;
                }

                if (! $this->selected($registration['bucket'])) {
                    continue;
                }

                $this->states[$provider] = 'activating';
                $routes = $this->app->make(Router::class)->getRoutes();
                $before = array_map(spl_object_id(...), $routes->getRoutes());

                try {
                    $receipts = $this->app->make(ExtensionContributionReceiptRegistry::class);
                    $context = TrustedCorePackages::contains($package)
                        ? ExtensionContributionReceiptContext::foundation($package, $registration['bucket'], $provider)
                        : ExtensionContributionReceiptContext::forPackage($package, $registration['bucket'], $provider);
                    $receipts->withContexts([$context], $registration['activate']);
                    $this->states[$provider] = 'active';
                    unset($this->pending[$package][$provider]);
                    if ($this->pending[$package] === []) {
                        unset($this->pending[$package]);
                    }

                    $this->activatedPackages[$package] = true;
                } catch (Throwable $throwable) {
                    // An opaque hook cannot roll back partially registered wiring.
                    $this->states[$provider] = 'failed';
                    $this->unavailable = true;
                    $this->recordFailure($throwable, $package, $provider, $registration['bucket'], 'installed-runtime-hook');

                    throw $throwable;
                } finally {
                    foreach ($routes->getRoutes() as $route) {
                        if (! in_array(spl_object_id($route), $before, true)) {
                            $this->packageRoutes[$package][] = $route;
                        }
                    }

                    if ($this->packageFailed($package)) {
                        foreach ($this->packageRoutes[$package] ?? [] as $route) {
                            $route->middleware(EnsureInstalledRuntimeAvailable::class . ':' . $package);
                            $route->computedMiddleware = null;
                        }
                    }
                }
            }
        } while (count($this->providers) !== $known);

        return $completed[$package] = true;
    }

    private function selected(string $bucket): bool
    {
        return in_array($bucket, ['runtime', 'auth', 'frontend'], true)
            || ($bucket === 'admin' && $this->app->make(RuntimeRoleResolver::class)->role()->loadsAuthoringProviders());
    }

    private function discovering(): bool
    {
        $arguments = $this->app->make('request')->server('argv', []);

        return is_array($arguments) && in_array('package:discover', $arguments, true);
    }
}
