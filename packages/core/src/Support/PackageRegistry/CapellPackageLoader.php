<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Capell\Core\Enums\SchemaProbeResult;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Bootstrap\CloudInstallContext;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class CapellPackageLoader
{
    private readonly CloudInstallContext $cloudInstallContext;

    private readonly RuntimeRoleResolver $runtimeRoleResolver;

    private readonly RuntimeRoleProviderPolicy $runtimeRoleProviderPolicy;

    public function __construct(
        private readonly Application $app,
        private readonly CapellPackageRegistry $registry,
        ?CloudInstallContext $cloudInstallContext = null,
        ?RuntimeRoleResolver $runtimeRoleResolver = null,
        ?RuntimeRoleProviderPolicy $runtimeRoleProviderPolicy = null,
    ) {
        $this->cloudInstallContext = $cloudInstallContext ?? CloudInstallContext::fromProcess();
        $this->runtimeRoleResolver = $runtimeRoleResolver ?? RuntimeRoleResolver::fromEnvironment();
        $this->runtimeRoleProviderPolicy = $runtimeRoleProviderPolicy ?? new RuntimeRoleProviderPolicy;
    }

    public function loadProviders(): void
    {
        foreach ($this->registry->all() as $manifest) {
            foreach ($this->resolveProviders($manifest) as $provider) {
                try {
                    if (! class_exists($provider)) {
                        continue;
                    }

                    $this->app->register($provider);
                } catch (Throwable $throwable) {
                    throw_if(TrustedCorePackages::contains($manifest->name), $throwable);

                    CapellCore::markPackageProviderQuarantined(
                        name: $manifest->name,
                        provider: $provider,
                        reason: $this->providerFailureReason($provider, $throwable),
                    );

                    break;
                }
            }
        }
    }

    /** @return list<string> */
    public function collectProviders(): array
    {
        $providers = [];

        foreach ($this->registry->all() as $manifest) {
            foreach ($this->resolveProviders($manifest) as $provider) {
                if (class_exists($provider)) {
                    $providers[] = $provider;
                }
            }
        }

        return $providers;
    }

    /** @return list<string> */
    private function resolveProviders(CapellManifestData $manifest): array
    {
        $runtimeRole = $this->runtimeRoleResolver->role();
        $providers = $runtimeRole->loadsAuthoringProviders()
            ? [...$manifest->providers->metadata, ...$manifest->providers->install]
            : $manifest->providers->metadata;

        if (! $this->shouldLoadRuntimeProviders($manifest)) {
            return array_values(array_unique($providers));
        }

        return array_values(array_unique(array_merge(
            $providers,
            $this->runtimeRoleProviderPolicy->extensionProviders($manifest->providers, $runtimeRole),
        )));
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

        return $schemaState->tableResult('capell_extensions') === SchemaProbeResult::Absent;
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
