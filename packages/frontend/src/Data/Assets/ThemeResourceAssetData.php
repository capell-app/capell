<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class ThemeResourceAssetData extends Data
{
    public function __construct(
        public string $source,
        public string $kind,
        public ?string $buildPath,
        public PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        public bool $defer = false,
        public bool $async = false,
        public bool $module = true,
    ) {}

    public static function fromDefinition(mixed $definition, ?string $defaultBuildPath): ?self
    {
        if (is_string($definition)) {
            return self::fromArrayDefinition(['source' => $definition], $defaultBuildPath);
        }

        if (! is_array($definition)) {
            return null;
        }

        return self::fromArrayDefinition($definition, $defaultBuildPath);
    }

    public function toFrontendResource(string $groupKey): FrontendResourceData
    {
        return new FrontendResourceData(
            handle: 'theme-resource:' . hash('xxh128', implode(':', [
                $groupKey,
                $this->kind,
                $this->buildPath ?? '',
                $this->source,
                $this->loadingStrategy->value,
            ])),
            kind: $this->kind,
            source: $this->source,
            buildPath: $this->buildPath,
            loadingStrategy: $this->loadingStrategy,
            defer: $this->defer,
            async: $this->async,
            module: $this->module,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function fromArrayDefinition(array $definition, ?string $defaultBuildPath): ?self
    {
        $source = $definition['source'] ?? $definition['path'] ?? null;

        if (! is_string($source) || $source === '') {
            return null;
        }

        $kind = $definition['kind'] ?? null;
        $loading = $definition['loadingStrategy'] ?? $definition['loading'] ?? $definition['loading_strategy'] ?? null;
        $buildPath = $definition['buildPath'] ?? $definition['build_path'] ?? $defaultBuildPath;

        return new self(
            source: $source,
            kind: is_string($kind) && $kind !== '' ? $kind : self::kindForSource($source),
            buildPath: is_string($buildPath) && $buildPath !== '' && ! self::isPublishedVendorAsset($source)
                ? $buildPath
                : null,
            loadingStrategy: is_string($loading)
                ? (PresentationLoadingStrategy::tryFrom($loading) ?? PresentationLoadingStrategy::Eager)
                : PresentationLoadingStrategy::Eager,
            defer: (bool) ($definition['defer'] ?? false),
            async: (bool) ($definition['async'] ?? false),
            module: (bool) ($definition['module'] ?? true),
        );
    }

    private static function kindForSource(string $source): string
    {
        return Str::endsWith($source, '.js')
            ? FrontendAssetRequirementData::KIND_JS
            : FrontendAssetRequirementData::KIND_CSS;
    }

    private static function isPublishedVendorAsset(string $source): bool
    {
        return Str::startsWith($source, ['vendor/', '/vendor/']);
    }
}
