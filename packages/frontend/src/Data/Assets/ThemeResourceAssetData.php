<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class ThemeResourceAssetData extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        public readonly bool $defer = true,
        public readonly bool $async = false,
        public readonly bool $module = true,
    ) {
        new PublicResourceSourceData($path);
    }

    public static function fromDefinition(mixed $definition, ?string $defaultBuildPath = null): ?self
    {
        $definition = is_string($definition) ? ['path' => $definition] : $definition;

        if (! is_array($definition)) {
            return null;
        }

        $path = $definition['path'] ?? $definition['source'] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            $source = new PublicResourceSourceData($path);
        } catch (InvalidArgumentException) {
            return null;
        }

        $loading = $definition['loadingStrategy'] ?? $definition['loading'] ?? $definition['loading_strategy'] ?? null;

        return new self(
            path: $source->path,
            loadingStrategy: is_string($loading) ? (PresentationLoadingStrategy::tryFrom($loading) ?? PresentationLoadingStrategy::Eager) : PresentationLoadingStrategy::Eager,
            defer: (bool) ($definition['defer'] ?? true),
            async: (bool) ($definition['async'] ?? false),
            module: (bool) ($definition['module'] ?? true),
        );
    }

    public function toFrontendResource(string $groupKey): FrontendResourceData
    {
        $source = new PublicResourceSourceData($this->path);
        $handle = 'capell-app/theme-metadata:' . hash('xxh128', $groupKey . ':' . $this->path);

        if (! Str::endsWith($this->path, '.js')) {
            return FrontendResourceData::style($handle, 'capell-app/theme-metadata', $source, $this->loadingStrategy);
        }

        return $this->module
            ? FrontendResourceData::moduleScript($handle, 'capell-app/theme-metadata', $source, $this->loadingStrategy, async: $this->async)
            : FrontendResourceData::classicScript($handle, 'capell-app/theme-metadata', $source, $this->loadingStrategy, defer: $this->defer, async: $this->async);
    }
}
