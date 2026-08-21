<?php

declare(strict_types=1);

namespace Capell\Core\Support\Runtime;

use Capell\Core\Data\Manifest\ExtensionProviderData;
use Capell\Core\Enums\RuntimeRole;

final class RuntimeRoleProviderPolicy
{
    /** @var list<string> */
    private const array AUTHORING_PACKAGE_NAMES = [
        'capell-app/admin',
        'capell-app/installer',
        'capell-app/marketplace',
        'lara-zeus/spatie-translatable',
        'tanmuhittin/laravel-google-translate',
    ];

    /** @var list<string> */
    private const array AUTHORING_PROVIDER_PREFIXES = [
        'Capell\\Admin\\',
        'Capell\\Installer\\',
        'Capell\\Marketplace\\',
        'Filament\\',
    ];

    /** @var list<string> */
    private const array AUTHORING_PROVIDER_FRAGMENTS = [
        '\\Filament\\',
        'FilamentTinyEditor\\',
        'FilamentShield\\',
        'FilamentAdjacencyList\\',
        'FilamentImpersonate\\',
        'FilamentPeek\\',
        'FilamentSelectTree\\',
        'FilamentClearCache\\',
        'BadgeableColumn\\',
        'IconPicker\\',
        'PluginEssentials\\',
        'SpatieTranslatable\\',
        'LaravelGoogleTranslate\\',
    ];

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>|null
     */
    public function filterLaravelPackage(string $package, array $configuration, RuntimeRole $role): ?array
    {
        if ($role->loadsAuthoringProviders()) {
            return $configuration;
        }

        if ($this->isAuthoringPackage($package)) {
            return null;
        }

        $containsAuthoringEntries = $this->containsAuthoringEntries($configuration);
        $providers = $configuration['providers'] ?? [];
        if (is_array($providers)) {
            $configuration['providers'] = array_values(array_filter(
                $providers,
                fn (mixed $provider): bool => is_string($provider) && ! $this->isAuthoringProvider($provider),
            ));
        }

        $aliases = $configuration['aliases'] ?? [];
        if (array_key_exists('aliases', $configuration) && is_array($aliases)) {
            $configuration['aliases'] = array_filter(
                $aliases,
                fn (mixed $alias): bool => ! is_string($alias) || ! $this->isAuthoringProvider($alias),
            );
        }

        if ($containsAuthoringEntries
            && ($configuration['providers'] ?? []) === []
            && ($configuration['aliases'] ?? []) === []) {
            return null;
        }

        return $configuration;
    }

    /**
     * @param  list<string>  $providers
     * @return list<string>
     */
    public function filterProviders(array $providers, RuntimeRole $role): array
    {
        if ($role->loadsAuthoringProviders()) {
            return array_values(array_unique($providers));
        }

        return array_values(array_unique(array_filter(
            $providers,
            fn (string $provider): bool => ! $this->isAuthoringProvider($provider),
        )));
    }

    /** @return list<class-string> */
    public function extensionProviders(ExtensionProviderData $providers, RuntimeRole $role): array
    {
        if ($role->loadsAuthoringProviders()) {
            return array_values(array_unique($providers->all()));
        }

        return array_values(array_unique([
            ...$providers->metadata,
            ...$providers->runtime,
            ...$providers->frontend,
            ...$providers->auth,
        ]));
    }

    public function isAuthoringProvider(string $provider): bool
    {
        foreach (self::AUTHORING_PROVIDER_PREFIXES as $prefix) {
            if (str_starts_with($provider, $prefix)) {
                return true;
            }
        }

        return array_any(
            self::AUTHORING_PROVIDER_FRAGMENTS,
            fn (string $fragment): bool => str_contains($provider, $fragment),
        );
    }

    public function isFrontendProvider(string $provider): bool
    {
        return str_starts_with($provider, 'Capell\\Frontend\\')
            && str_ends_with($provider, '\\FrontendServiceProvider');
    }

    public function isAuthoringPackage(string $package): bool
    {
        return in_array($package, self::AUTHORING_PACKAGE_NAMES, true)
            || str_starts_with($package, 'filament/')
            || str_contains($package, '/filament-')
            || str_contains($package, 'filament-plugin');
    }

    /** @param array<string, mixed> $configuration */
    private function containsAuthoringEntries(array $configuration): bool
    {
        $providers = $configuration['providers'] ?? [];
        $aliases = $configuration['aliases'] ?? [];

        return (is_array($providers) && array_any(
            $providers,
            fn (mixed $provider): bool => is_string($provider) && $this->isAuthoringProvider($provider),
        )) || (is_array($aliases) && array_any(
            $aliases,
            fn (mixed $alias): bool => is_string($alias) && $this->isAuthoringProvider($alias),
        ));
    }
}
