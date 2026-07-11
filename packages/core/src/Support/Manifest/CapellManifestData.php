<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest;

use Capell\Core\Data\Manifest\ExtensionCommercialData;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\Manifest\ExtensionDependencyData;
use Capell\Core\Data\Manifest\ExtensionHealthCheckData;
use Capell\Core\Data\Manifest\ExtensionPerformanceBudgetData;
use Capell\Core\Data\Manifest\ExtensionProviderData;
use Capell\Core\Data\Manifest\ExtensionScreenshotData;
use Capell\Core\Data\Manifest\ExtensionSecurityData;
use Capell\Core\Enums\ExtensionManifestVersion;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;

final class CapellManifestData
{
    /**
     * @param  list<string>  $surfaces
     * @param  list<string>  $requires
     * @param  list<string>  $supports
     * @param  list<string>  $conflicts
     * @param  list<ExtensionContributionData>  $contributes
     * @param  array<string, mixed>  $database
     * @param  array<string, mixed>  $commands
     * @param  array<string, mixed>  $actions
     * @param  list<string>  $settings
     * @param  list<string>  $permissions
     * @param  list<string>  $capabilities
     * @param  list<ExtensionHealthCheckData>  $healthChecks
     * @param  list<ExtensionScreenshotData>  $marketplaceScreenshots
     * @param  list<string>  $marketplaceCategories
     * @param  list<string>  $scopes
     */
    public function __construct(
        public int $manifestVersion,
        public string $name,
        public string $slug,
        public string $displayName,
        public string $kind,
        public string $capellApiVersion,
        public string $version,
        public ?string $description,
        public string $productGroup,
        public string $tier,
        public ?string $bundle,
        public array $surfaces,
        public ExtensionDependencyData $dependencies,
        public array $requires,
        public array $supports,
        public array $conflicts,
        public ExtensionProviderData $providers,
        public array $contributes,
        public array $database,
        public array $commands,
        public array $actions,
        public array $settings,
        public array $permissions,
        public array $capabilities,
        public ?ExtensionSecurityData $security,
        public ExtensionPerformanceBudgetData $performance,
        public array $healthChecks,
        public ExtensionCommercialData $commercial,
        public ?string $marketplaceSummary,
        public array $marketplaceScreenshots,
        public array $marketplaceCategories,
        public bool $marketplaceHidden = false,
        public array $scopes = [],
        public ?string $namespace = null,
        public ?string $extends = null,
        public ?string $themeKey = null,
        public string $runtime = 'blade',
        public bool $defaultSelected = false,
        public bool $demo = false,
        public int $order = 0,
        public ?string $installPath = null,
        public string $visibility = 'catalogue',
        public ?string $documentationUrl = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $installPath = null, ?string $documentationUrl = null): self
    {
        if (($data['manifest-version'] ?? null) !== ExtensionManifestVersion::V3->value) {
            throw InvalidManifestException::missingField('manifest-version 3');
        }

        $product = is_array($data['product'] ?? null) ? $data['product'] : [];
        $marketplace = is_array($data['marketplace'] ?? null) ? $data['marketplace'] : [];

        return new self(
            manifestVersion: ExtensionManifestVersion::V3->value,
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            displayName: (string) $data['displayName'],
            kind: (string) $data['kind'],
            capellApiVersion: (string) $data['capellApiVersion'],
            version: (string) $data['version'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            productGroup: (string) ($product['group'] ?? ''),
            tier: (string) ($product['tier'] ?? ''),
            bundle: isset($product['bundle']) ? (string) $product['bundle'] : null,
            surfaces: self::stringList($data['surfaces'] ?? []),
            dependencies: ExtensionDependencyData::fromArray(is_array($data['dependencies'] ?? null) ? $data['dependencies'] : []),
            requires: self::stringList($data['dependencies']['requires'] ?? []),
            supports: self::stringList($data['dependencies']['supports'] ?? []),
            conflicts: self::stringList($data['dependencies']['conflicts'] ?? []),
            providers: ExtensionProviderData::fromArray(is_array($data['providers'] ?? null) ? $data['providers'] : []),
            contributes: array_map(
                ExtensionContributionData::fromArray(...),
                self::arrayList($data['contributes'] ?? []),
            ),
            database: is_array($data['database'] ?? null) ? $data['database'] : [],
            commands: is_array($data['commands'] ?? null) ? $data['commands'] : [],
            actions: is_array($data['actions'] ?? null) ? $data['actions'] : [],
            settings: self::stringList($data['settings'] ?? []),
            permissions: self::stringList($data['permissions'] ?? []),
            capabilities: self::stringList($data['capabilities'] ?? []),
            security: is_array($data['security'] ?? null) ? ExtensionSecurityData::fromArray($data['security']) : null,
            performance: ExtensionPerformanceBudgetData::fromArray(is_array($data['performance'] ?? null) ? $data['performance'] : []),
            healthChecks: array_map(
                ExtensionHealthCheckData::fromArray(...),
                self::arrayList($data['healthChecks'] ?? []),
            ),
            commercial: ExtensionCommercialData::fromArray(is_array($data['commercial'] ?? null) ? $data['commercial'] : []),
            marketplaceSummary: isset($marketplace['summary']) ? (string) $marketplace['summary'] : null,
            marketplaceScreenshots: array_map(
                ExtensionScreenshotData::fromArray(...),
                self::arrayList($marketplace['screenshots'] ?? []),
            ),
            marketplaceCategories: self::stringList($marketplace['categories'] ?? []),
            marketplaceHidden: self::booleanValue($marketplace['hidden'] ?? false),
            scopes: self::stringList($data['scopes'] ?? []),
            namespace: isset($data['namespace']) ? (string) $data['namespace'] : null,
            extends: isset($data['extends']) ? (string) $data['extends'] : null,
            themeKey: isset($data['themeKey']) ? (string) $data['themeKey'] : null,
            runtime: isset($data['runtime']) ? (string) $data['runtime'] : 'blade',
            defaultSelected: self::booleanValue($data['defaultSelected'] ?? false),
            demo: self::booleanValue($data['demo'] ?? false),
            order: isset($data['order']) ? (int) $data['order'] : 0,
            installPath: $installPath ?? (isset($data['installPath']) ? (string) $data['installPath'] : null),
            visibility: isset($data['visibility']) ? (string) $data['visibility'] : 'catalogue',
            documentationUrl: self::stringValue($data['documentationUrl'] ?? null) ?? self::stringValue($documentationUrl),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $marketplace = [
            'summary' => $this->marketplaceSummary,
            'screenshots' => array_map(
                static fn (ExtensionScreenshotData $screenshot): array => $screenshot->toArray(),
                $this->marketplaceScreenshots,
            ),
            'categories' => $this->marketplaceCategories,
        ];

        if ($this->marketplaceHidden) {
            $marketplace['hidden'] = true;
        }

        $data = [
            'manifest-version' => $this->manifestVersion,
            'name' => $this->name,
            'slug' => $this->slug,
            'displayName' => $this->displayName,
            'kind' => $this->kind,
            'capellApiVersion' => $this->capellApiVersion,
            'version' => $this->version,
            ...($this->description !== null ? ['description' => $this->description] : []),
            'product' => [
                'group' => $this->productGroup,
                'tier' => $this->tier,
                'bundle' => $this->bundle,
            ],
            'surfaces' => $this->surfaces,
            'dependencies' => $this->dependencies->toArray(),
            'providers' => $this->providers->toArray(),
            'contributes' => array_map(
                static fn (ExtensionContributionData $contribution): array => $contribution->toArray(),
                $this->contributes,
            ),
            'database' => $this->database,
            'commands' => $this->commands,
            'settings' => $this->settings,
            'permissions' => $this->permissions,
            'capabilities' => $this->capabilities,
            ...($this->security instanceof ExtensionSecurityData ? ['security' => $this->security->toArray()] : []),
            'performance' => $this->performance->toArray(),
            'healthChecks' => array_map(
                static fn (ExtensionHealthCheckData $healthCheck): array => $healthCheck->toArray(),
                $this->healthChecks,
            ),
            'commercial' => $this->commercial->toArray(),
            'marketplace' => $marketplace,
        ];

        if ($this->actions !== []) {
            $data['actions'] = $this->actions;
        }

        if ($this->scopes !== []) {
            $data['scopes'] = $this->scopes;
        }

        if ($this->namespace !== null) {
            $data['namespace'] = $this->namespace;
        }

        if ($this->extends !== null) {
            $data['extends'] = $this->extends;
        }

        if ($this->themeKey !== null) {
            $data['themeKey'] = $this->themeKey;
        }

        if ($this->runtime !== 'blade') {
            $data['runtime'] = $this->runtime;
        }

        if ($this->order !== 0) {
            $data['order'] = $this->order;
        }

        if ($this->defaultSelected) {
            $data['defaultSelected'] = true;
        }

        if ($this->demo) {
            $data['demo'] = true;
        }

        if ($this->installPath !== null) {
            $data['installPath'] = $this->installPath;
        }

        if ($this->visibility !== 'catalogue') {
            $data['visibility'] = $this->visibility;
        }

        if ($this->documentationUrl !== null) {
            $data['documentationUrl'] = $this->documentationUrl;
        }

        return $data;
    }

    public function resolvedNamespace(): ?string
    {
        if ($this->namespace !== null) {
            return $this->namespace;
        }

        foreach ($this->providers->all() as $provider) {
            $parts = explode('\\', $provider);

            if (count($parts) >= 2) {
                return $parts[0] . '\\' . $parts[1];
            }
        }

        return null;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /** @return list<array<string, mixed>> */
    private static function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
