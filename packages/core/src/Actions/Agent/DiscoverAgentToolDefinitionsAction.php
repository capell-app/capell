<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Agent;

use Capell\Core\Contracts\Agent\DefinesAgentTool;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Agent\AgentToolDefinitionNormalizer;
use Capell\Core\Support\Agent\AgentToolRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Discover trusted public agent tools after package providers have booted.
 *
 * Manifest validation remains data-only. This action is the explicit seam at
 * which an enabled package's typed declaration is invoked and normalised.
 *
 * @method static AgentToolRegistry run(array<string, CapellManifestData>|null $manifests = null, ?AgentToolRegistry $registry = null)
 */
final class DiscoverAgentToolDefinitionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, CapellManifestData>|null  $manifests
     */
    public function handle(?array $manifests = null, ?AgentToolRegistry $registry = null): AgentToolRegistry
    {
        $packageRegistry = resolve(CapellPackageRegistry::class);
        $manifests ??= $packageRegistry->all();
        $registry ??= new AgentToolRegistry;
        $normalizer = new AgentToolDefinitionNormalizer;

        foreach ($manifests as $manifest) {
            if (! $packageRegistry->isPackageEnabled($manifest->name)) {
                continue;
            }

            if ($this->hasPublicFrontendSurface($manifest)) {
                foreach ($manifest->agentTools as $declaration) {
                    if (is_array($declaration)) {
                        $registry->register($normalizer->normalize($declaration, $manifest->name));
                    }
                }
            }

            foreach ($manifest->contributes as $contribution) {
                if (! $this->isDiscoverable($manifest, $contribution)) {
                    continue;
                }

                $class = $contribution->class;
                if ($class === null) {
                    continue;
                }

                if (! class_exists($class)) {
                    continue;
                }

                if (! is_a($class, DefinesAgentTool::class, true)) {
                    continue;
                }

                /** @var class-string<DefinesAgentTool> $class */
                $registry->register($class::agentToolDefinition()->withOwnerPackage($manifest->name));
            }
        }

        return $registry;
    }

    private function hasPublicFrontendSurface(CapellManifestData $manifest): bool
    {
        return in_array('frontend', $manifest->surfaces, true);
    }

    private function isDiscoverable(CapellManifestData $manifest, ExtensionContributionData $contribution): bool
    {
        if ($contribution->type !== ExtensionContributionType::AgentCapability
            || ! $this->isPublicDeclaration($contribution->metadata)) {
            return false;
        }

        $providerBucket = $contribution->providerBucket ?? $contribution->type->bucket();
        if (! in_array($providerBucket, ['runtime', 'frontend'], true)) {
            return false;
        }

        $surface = $contribution->metadata['surface'] ?? null;
        if ($surface === 'admin' || ($contribution->metadata['context'] ?? null) === 'admin') {
            return false;
        }

        return ($surface === null && in_array('frontend', $manifest->surfaces, true))
            || in_array($surface, ['frontend', 'public'], true);
    }

    /** @param array<string, mixed> $metadata */
    private function isPublicDeclaration(array $metadata): bool
    {
        return ($metadata['context'] ?? null) === 'public'
            || ($metadata['surface'] ?? null) === 'public'
            || ($metadata['public'] ?? false) === true;
    }
}
