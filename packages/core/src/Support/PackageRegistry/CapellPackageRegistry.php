<?php

declare(strict_types=1);

namespace Capell\Core\Support\PackageRegistry;

use Capell\Core\Actions\Extensions\BuildExtensionContractRegistryAction;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Manifest\CapellManifestData;
use Illuminate\Support\Str;

final class CapellPackageRegistry
{
    /** @var array<string, CapellManifestData> */
    private array $packages = [];

    /** @var array{byType: array<string, list<ExtensionContributionData>>, byPackage: array<string, list<ExtensionContributionData>>, bySurface: array<string, list<ExtensionContributionData>>, byClass: array<string, ExtensionContributionData>}|null */
    private ?array $contractRegistry = null;

    /** @param array<string, CapellManifestData> $manifests */
    public function fill(array $manifests): void
    {
        $this->packages = $manifests;
        $this->contractRegistry = null;
    }

    public function get(string $name): ?CapellManifestData
    {
        return $this->packages[$name] ?? null;
    }

    /** @return array<string, CapellManifestData> */
    public function all(): array
    {
        return $this->packages;
    }

    public function has(string $name): bool
    {
        return isset($this->packages[$name]);
    }

    /**
     * Builds a map of PHP namespace prefix → package short name for all registered packages.
     * The short name is the portion of the composer package name after the final slash
     * (e.g. `capell-app/seo-suite` → `seo-suite`).
     *
     * @return array<string, string>
     */
    public function namespaceMap(): array
    {
        $map = [];

        foreach ($this->packages as $manifest) {
            $resolvedNamespace = $manifest->resolvedNamespace();

            if ($resolvedNamespace === null) {
                continue;
            }

            // Str::afterLast keeps the whole name when there is no slash, unlike
            // the old (int) strrpos(...) + 1 which dropped the first character.
            $shortName = Str::afterLast($manifest->name, '/');
            $map[$resolvedNamespace . '\\'] = $shortName;
        }

        return $map;
    }

    /** @return list<CapellManifestData> */
    public function forContext(string $context): array
    {
        return array_values(array_filter(
            $this->packages,
            fn (CapellManifestData $manifest): bool => in_array($context, $manifest->surfaces, strict: true),
        ));
    }

    /** @return list<ExtensionContributionData> */
    public function contributionsForType(ExtensionContributionType $type): array
    {
        return $this->contractRegistry()['byType'][$type->value] ?? [];
    }

    /** @return list<ExtensionContributionData> */
    public function contributionsForPackage(string $packageName): array
    {
        return $this->contractRegistry()['byPackage'][$packageName] ?? [];
    }

    /** @return list<ExtensionContributionData> */
    public function contributionsForSurface(string $surface): array
    {
        return $this->contractRegistry()['bySurface'][$surface] ?? [];
    }

    public function contributionForClass(string $class): ?ExtensionContributionData
    {
        return $this->contractRegistry()['byClass'][$class] ?? null;
    }

    /** @return array{byType: array<string, list<ExtensionContributionData>>, byPackage: array<string, list<ExtensionContributionData>>, bySurface: array<string, list<ExtensionContributionData>>, byClass: array<string, ExtensionContributionData>} */
    private function contractRegistry(): array
    {
        if ($this->contractRegistry === null) {
            $this->contractRegistry = BuildExtensionContractRegistryAction::run($this->packages);
        }

        return $this->contractRegistry;
    }
}
