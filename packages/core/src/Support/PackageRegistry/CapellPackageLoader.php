<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Capell\Core\Actions\Install\RegisterProviderCommandsInCurrentProcessAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\SchemaProbeResult;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Bootstrap\CloudInstallContext;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Marketplace\ExtensionCommercialPolicy;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Runtime\RuntimeRolePackageManifest;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Core\Support\TableName;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\PackageManifest;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

final class CapellPackageLoader
{
    private readonly CloudInstallContext $cloudInstallContext;

    private readonly RuntimeRoleResolver $runtimeRoleResolver;

    private readonly RuntimeRoleProviderPolicy $runtimeRoleProviderPolicy;

    private readonly ExtensionContributionReceiptRegistry $receipts;

    public function __construct(
        private readonly Application $app,
        private readonly CapellPackageRegistry $registry,
        ?CloudInstallContext $cloudInstallContext = null,
        ?RuntimeRoleResolver $runtimeRoleResolver = null,
        ?RuntimeRoleProviderPolicy $runtimeRoleProviderPolicy = null,
        ?ExtensionContributionReceiptRegistry $receipts = null,
    ) {
        $this->cloudInstallContext = $cloudInstallContext ?? CloudInstallContext::fromProcess();
        $this->runtimeRoleResolver = $runtimeRoleResolver ?? RuntimeRoleResolver::fromEnvironment();
        $this->runtimeRoleProviderPolicy = $runtimeRoleProviderPolicy ?? new RuntimeRoleProviderPolicy;
        $this->receipts = $receipts ?? new ExtensionContributionReceiptRegistry;
    }

    /**
     * @return list<class-string>
     */
    public function loadProviders(): array
    {
        return $this->app->make(InstalledRuntimeLifecycle::class)->duringProviderRegistration($this->registerProviders(...));
    }

    /** Refresh only eligible runtime buckets; preserve legacy provider callbacks. */
    public function refreshPackage(PackageData $package, bool $replayBootedCallbacks = true): void
    {
        $this->app->make(InstalledRuntimeLifecycle::class)->duringProviderRegistration(function () use ($package, $replayBootedCallbacks): void {
            RegisterProviderCommandsInCurrentProcessAction::run(function () use ($package, $replayBootedCallbacks): void {
                $this->registerPackageProviders($package, $replayBootedCallbacks);
            });
        }, $package->name);
    }

    /** @return list<string> */
    public function collectProviders(): array
    {
        $providers = [];

        foreach ($this->registry->all() as $manifest) {
            foreach ($this->resolveRegistration($manifest)['providers'] as $provider) {
                if (class_exists($provider)) {
                    $providers[] = $provider;
                }
            }
        }

        return $providers;
    }

    /** @return list<class-string> */
    private function registerProviders(): array
    {
        $loadedProviders = [];

        foreach ($this->registry->all() as $manifest) {
            $runtimeProvidersSelected = $this->shouldLoadRuntimeProviders($manifest);
            $snapshot = ProviderRegistrationSnapshot::take($this->app);
            $packageProviders = [];
            ['providers' => $providers, 'aliases' => $aliases] = $this->resolveRegistration($manifest, $runtimeProvidersSelected);
            $previousAliases = null;
            if ($aliases !== []) {
                $previousAliases = AliasLoader::getInstance()->getAliases();
                AliasLoader::getInstance($aliases)->register();
            }

            foreach ($providers as $provider) {
                try {
                    if (! class_exists($provider)) {
                        continue;
                    }

                    $contexts = array_map(
                        fn (string $bucket): ExtensionContributionReceiptContext => $manifest->name === 'capell-app/core'
                            ? ExtensionContributionReceiptContext::foundation($manifest->name, $bucket, $provider)
                            : ExtensionContributionReceiptContext::forPackage($manifest->name, $bucket, $provider),
                        $this->selectedProviderBuckets($manifest, $provider, $runtimeProvidersSelected),
                    );

                    $this->receipts->withContexts($contexts, fn (): mixed => RegisterProviderCommandsInCurrentProcessAction::run(
                        fn (): mixed => $this->app->register($provider),
                    ));
                    $loadedProviders[] = $provider;
                    $packageProviders[] = $provider;
                    foreach ($contexts as $context) {
                        $this->receipts->rememberProviderContext($provider, $context);
                    }

                    $namespace = $manifest->resolvedNamespace();
                    if ($namespace !== null) {
                        foreach ($contexts as $context) {
                            $this->receipts->rememberNamespaceContext($namespace, $context);
                        }
                    }
                } catch (Throwable $throwable) {
                    if ($previousAliases !== null) {
                        AliasLoader::getInstance()->setAliases($previousAliases);
                    }

                    throw_if(
                        TrustedCorePackages::contains($manifest->name)
                        || ($this->app->resolved(InstalledRuntimeLifecycle::class)
                            && $this->app->make(InstalledRuntimeLifecycle::class)->failed($throwable)),
                        $throwable,
                    );

                    CapellCore::markPackageProviderQuarantined(
                        name: $manifest->name,
                        provider: $provider,
                        reason: $this->providerFailureReason($provider, $throwable),
                    );

                    $snapshot?->restore();
                    $loadedProviders = array_values(array_diff($loadedProviders, $packageProviders));

                    break;
                }
            }
        }

        return $loadedProviders;
    }

    private function registerPackageProviders(PackageData $package, bool $replayBootedCallbacks): void
    {
        if (! CapellCore::isPackageEnabled($package->name)) {
            return;
        }

        $manifest = $package->manifest;
        if ($manifest instanceof CapellManifestData) {
            ['providers' => $selected, 'aliases' => $aliases] = $this->resolveRegistration($manifest, true);
            if ($aliases !== []) {
                AliasLoader::getInstance($aliases)->register();
            }

            $discovered = array_diff($selected, $manifest->providers->all());
            foreach (['auth', 'runtime', 'admin', 'frontend'] as $bucket) {
                $providers = $package->getProviderClasses($bucket);
                if ($bucket === 'runtime') {
                    $providers = [...$providers, ...$discovered];
                }

                foreach ($providers as $provider) {
                    if (! in_array($provider, $selected, true)) {
                        continue;
                    }

                    if (! class_exists($provider)) {
                        continue;
                    }

                    if (! in_array($bucket, $this->selectedProviderBuckets($manifest, $provider, true), true)) {
                        continue;
                    }

                    if (! $replayBootedCallbacks && ! $this->app->providerIsLoaded($provider) && ! InstalledRuntimeLifecycle::adopts($provider)) {
                        continue;
                    }

                    $context = TrustedCorePackages::contains($manifest->name)
                        ? ExtensionContributionReceiptContext::foundation($manifest->name, $bucket, $provider)
                        : ExtensionContributionReceiptContext::forPackage($manifest->name, $bucket, $provider);
                    $this->receipts->rememberProviderContext($provider, $context);
                    $this->receipts->withContexts([$context], function () use ($provider, $replayBootedCallbacks): void {
                        $this->app->register($provider);
                        if ($replayBootedCallbacks) {
                            $this->app->getProvider($provider)?->callBootedCallbacks();
                        }
                    });
                }
            }
        }

        if ($replayBootedCallbacks && $package->serviceProviderClass !== null) {
            $provider = $this->app->getProvider($package->serviceProviderClass);
            if ($provider instanceof PackageServiceProvider) {
                $provider->callBootedCallbacks();
            }
        }
    }

    /** @return array{providers: list<string>, aliases: array<string, string>} */
    private function resolveRegistration(CapellManifestData $manifest, ?bool $runtimeProvidersSelected = null): array
    {
        $commercial = resolve(ExtensionCommercialPolicy::class);
        $enforcing = $commercial->enforcing();

        // Once issuer enforcement is on, an unauthorised commercial package loads no provider in
        // any bucket, metadata included: its code does not run at all.
        if ($enforcing
            && ! resolve(ExtensionLifecycleRepository::class)->commerciallyAuthorised($manifest->name, CapellCore::getPackages(withoutCore: false))) {
            return ['providers' => [], 'aliases' => []];
        }

        $runtimeProvidersSelected ??= $this->shouldLoadRuntimeProviders($manifest);
        $discovery = $enforcing && $runtimeProvidersSelected
            ? $this->deferredDiscovery($manifest)
            : ['providers' => [], 'aliases' => []];

        // The boot cache can name classes the vendor metadata does not. Paid classes there
        // require an authorisation decision for the package itself.
        if ($commercial->classesArePaid([...$manifest->providers->all(), ...$discovery['providers'], ...array_values($discovery['aliases'])])
            && ! $commercial->requiresAuthorisation($manifest->name)) {
            return ['providers' => [], 'aliases' => []];
        }

        $runtimeRole = $this->runtimeRoleResolver->role();
        $providers = $runtimeRole->loadsAuthoringProviders()
            ? [...$manifest->providers->metadata, ...$manifest->providers->install]
            : $manifest->providers->metadata;

        if (! $runtimeProvidersSelected) {
            return ['providers' => array_values(array_unique($providers)), 'aliases' => []];
        }

        return [
            'providers' => array_values(array_unique([
                ...$providers,
                ...$this->runtimeRoleProviderPolicy->extensionProviders($manifest->providers, $runtimeRole),
                ...$discovery['providers'],
            ])),
            'aliases' => $discovery['aliases'],
        ];
    }

    /**
     * Replay Laravel discovery from the filtered manifest, preserving discovery opt-outs
     * and role filtering. Laravel-only providers and aliases count as runtime wiring.
     *
     * @return array{providers: list<string>, aliases: array<string, string>}
     */
    private function deferredDiscovery(CapellManifestData $manifest): array
    {
        if (! $this->app->bound(PackageManifest::class)) {
            return ['providers' => [], 'aliases' => []];
        }

        $discovery = $this->app->make(PackageManifest::class);
        if (! $discovery instanceof RuntimeRolePackageManifest) {
            return ['providers' => [], 'aliases' => []];
        }

        $deferred = $discovery->deferredDiscoveryFor($manifest->name);
        $declared = array_map(static fn (string $provider): string => strtolower(ltrim($provider, '\\')), $manifest->providers->all());
        $deferred['providers'] = array_values(array_filter(
            $deferred['providers'],
            static fn (string $provider): bool => ! in_array(strtolower(ltrim($provider, '\\')), $declared, true),
        ));

        return $deferred;
    }

    private function shouldLoadRuntimeProviders(CapellManifestData $manifest): bool
    {
        if (TrustedCorePackages::contains($manifest->name)) {
            return true;
        }

        if ($this->cloudInstallContext->isCloudInstall() && $this->lifecycleLedgerIsUnavailable()) {
            return $this->cloudInstallContext->selects($manifest->name);
        }

        return CapellCore::isPackageEnabled($manifest->name);
    }

    /** @return list<string> */
    private function selectedProviderBuckets(
        CapellManifestData $manifest,
        string $provider,
        bool $runtimeProvidersSelected,
    ): array {
        $role = $this->runtimeRoleResolver->role();
        $selected = ['metadata'];

        if ($role->loadsAuthoringProviders()) {
            $selected[] = 'install';
        }

        if ($runtimeProvidersSelected) {
            $selected = [...$selected, 'runtime', 'auth'];

            if ($role->loadsAuthoringProviders()) {
                $selected[] = 'admin';
            }

            $selected[] = 'frontend';
        }

        $buckets = array_values(array_filter(
            $selected,
            fn (string $bucket): bool => in_array($provider, $manifest->providers[$bucket], true),
        ));

        // Every selected provider outside the manifest buckets comes from deferred discovery.
        return $buckets === [] && $runtimeProvidersSelected ? ['runtime'] : $buckets;
    }

    /**
     * Cloud provisioning needs selected providers only while the extension
     * ledger does not yet exist. The environment remains set after install, so
     * every later boot must return to the persisted package lifecycle state.
     */
    private function lifecycleLedgerIsUnavailable(): bool
    {
        if (! $this->app->bound('db')) {
            return true;
        }

        /** @var RuntimeSchemaState $schemaState */
        $schemaState = $this->app->make(RuntimeSchemaState::class);

        return $schemaState->tableResult(TableName::resolve('capell_extensions')) === SchemaProbeResult::Absent;
    }

    private function providerFailureReason(string $provider, Throwable $throwable): string
    {
        return sprintf(
            'Provider [%s] failed during registration with [%s].',
            $provider,
            $throwable::class,
        );
    }
}
