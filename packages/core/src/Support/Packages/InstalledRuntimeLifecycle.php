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

    /** @var WeakReference<Throwable>|null */
    private ?WeakReference $lastFailure = null;

    /** @var array<string, true> */
    private array $activatedPackages = [];

    public function __construct(private readonly Application $app) {}

    /**
     * @param  class-string  $provider
     * @param  Closure(): void  $activate
     */
    public function register(string $provider, string $package, string $bucket, Closure $activate): void
    {
        $this->providers[$provider] ??= ['package' => $package, 'bucket' => $bucket, 'activate' => $activate];
    }

    public function refresh(): void
    {
        if ($this->refreshing || $this->discovering()) {
            return;
        }

        $this->refreshing = true;

        try {
            $this->app->make(PackageSurfaceRegistrar::class)->duringPackageInstallation(function (): void {
                do {
                    $known = count($this->providers);
                    foreach ($this->providers as $registration) {
                        $this->activatePackage($registration['package'], []);
                    }
                } while (count($this->providers) !== $known);

                $activated = array_keys($this->activatedPackages);
                foreach ($activated as $package) {
                    $this->app->make(Dispatcher::class)->dispatch(new InstalledRuntimeRefreshed(CapellCore::getPackage($package)));
                    unset($this->activatedPackages[$package]);
                }
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

    /** @param list<string> $ancestors */
    private function activatePackage(string $package, array $ancestors): bool
    {
        if (! CapellCore::isPackageEnabled($package)) {
            return false;
        }

        if (in_array($package, $ancestors, true)) {
            throw new RuntimeException('Circular installed-runtime dependency: ' . implode(' -> ', [...$ancestors, $package]));
        }

        foreach (CapellCore::getPackage($package)->getRequirements() as $requirement) {
            if (! $this->activatePackage($requirement, [...$ancestors, $package])) {
                return false;
            }
        }

        do {
            $known = count($this->providers);
            foreach ($this->providers as $provider => $registration) {
                if ($registration['package'] !== $package) {
                    continue;
                }

                if (in_array($this->states[$provider] ?? null, ['activating', 'active'], true)) {
                    continue;
                }

                if (! $this->selected($registration['bucket'])) {
                    continue;
                }

                $this->states[$provider] = 'activating';

                try {
                    // Octane clones inherit providers bound to their original application.
                    // Activating those in a sandbox would mark incomplete wiring as active.
                    throw_if(Container::getInstance() !== $this->app, RuntimeException::class, 'Installed runtime activation requires the owning application; install outside the request sandbox and reload retained workers.');

                    $receipts = $this->app->make(ExtensionContributionReceiptRegistry::class);
                    $context = TrustedCorePackages::contains($package)
                        ? ExtensionContributionReceiptContext::foundation($package, $registration['bucket'], $provider)
                        : ExtensionContributionReceiptContext::forPackage($package, $registration['bucket'], $provider);
                    $receipts->withContexts([$context], $registration['activate']);
                    $this->states[$provider] = 'active';
                    $this->activatedPackages[$package] = true;
                } catch (Throwable $throwable) {
                    // Laravel registration is not transactional. Retrying is possible,
                    // but the hook must make any work preceding the exception retry-safe.
                    $this->states[$provider] = 'failed';

                    throw $throwable;
                }
            }
        } while (count($this->providers) !== $known);

        return true;
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
