<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Events\InstalledRuntimeRefreshed;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use WeakReference;

/** Application-bootstrap state: never reset this service at request/job boundaries. */
final class InstalledRuntimeLifecycle
{
    /** @var array<class-string, array{package: string, bucket: string, activate: Closure(): void}> */
    private array $providers = [];

    /** @var array<class-string, 'activating'|'active'|'failed'> */
    private array $states = [];

    private bool $refreshing = false;

    private bool $unavailable = false;

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

    public function __construct(private readonly Application $app) {}

    /** @param class-string $provider */
    public static function adopts(string $provider): bool
    {
        return in_array(RegistersInstalledRuntime::class, class_uses_recursive($provider), true)
            && new ReflectionMethod($provider, 'bootInstalledRuntime')->getDeclaringClass()->getName() !== AbstractPackageServiceProvider::class;
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

    public function assertCanActivate(): void
    {
        if (Container::getInstance() !== $this->app) {
            throw new RuntimeException(__('capell::runtime-refresh.owning_application_required'));
        }

        if ($this->unavailable) {
            throw new RuntimeException(__('capell::runtime-refresh.failed_application'));
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
        if ($this->unavailable) {
            throw new RuntimeException(__('capell::runtime-refresh.failed_application'));
        }

        if (isset($this->bootedProviders[$provider])) {
            return;
        }

        $this->bootedProviders[$provider] = true;
        if ($this->app->isBooted()) {
            $this->refresh();

            return;
        }

        if (! $this->bootRefreshScheduled) {
            $this->bootRefreshScheduled = true;
            $this->app->booted($this->refresh(...));
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function duringProviderRegistration(Closure $callback): mixed
    {
        $this->registrationDepth++;
        try {
            $result = $callback();
        } finally {
            $this->registrationDepth--;
        }

        if ($this->app->isBooted()) {
            $this->refresh();
        }

        return $result;
    }

    public function refresh(): void
    {
        if ($this->refreshing || $this->registrationDepth > 0 || $this->discovering()) {
            return;
        }

        if ($this->unavailable) {
            throw new RuntimeException(__('capell::runtime-refresh.failed_application'));
        }

        if ($this->pending === [] && $this->activatedPackages === []) {
            return;
        }

        $this->assertCanActivate();
        $this->refreshing = true;

        try {
            $this->app->make(PackageSurfaceRegistrar::class)->duringPackageInstallation(function (): void {
                do {
                    $known = count($this->providers);
                    $completed = [];
                    foreach (array_keys($this->pending) as $package) {
                        $this->activatePackage($package, [], $completed);
                    }
                } while (count($this->providers) !== $known);

                $activated = array_keys($this->activatedPackages);
                foreach ($activated as $package) {
                    if ($this->app->isBooted()) {
                        $this->app->make(Dispatcher::class)->dispatch(new InstalledRuntimeRefreshed(CapellCore::getPackage($package)));
                    }

                    unset($this->activatedPackages[$package]);
                }
            });
        } catch (Throwable $throwable) {
            // Preserve identity for the loader without retaining a failed job's trace.
            $this->lastFailure = WeakReference::create($throwable);
            $this->unavailable = true;

            throw $throwable;
        } finally {
            $this->refreshing = false;
        }
    }

    public function failed(Throwable $throwable): bool
    {
        return $this->lastFailure?->get() === $throwable;
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

                    throw $throwable;
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
