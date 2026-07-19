<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use BackedEnum;
use Capell\Core\Support\Components\ComponentRegistry;

trait HasComponents
{
    public static function getComponentTypeFromDirectory(string $directory): string
    {
        return ComponentRegistry::getComponentTypeFromDirectory($directory);
    }

    public function registerComponent(string|BackedEnum $type, string|BackedEnum $name, string $component): static
    {
        resolve(ComponentRegistry::class)->registerComponent($type, $name, $component);

        return $this;
    }

    /**
     * @param  array<int|string, string|BackedEnum>  $components
     */
    public function registerComponents(string|BackedEnum $type, array $components): static
    {
        resolve(ComponentRegistry::class)->registerComponents($type, $components);

        return $this;
    }

    /**
     * @return array<string, string>|array<string, array<string, string>>
     */
    public function getComponents(null|string|BackedEnum $type = null): array
    {
        return resolve(ComponentRegistry::class)->getComponents($type);
    }

    public function getComponent(string|BackedEnum $type, string $name): string
    {
        return resolve(ComponentRegistry::class)->getComponent($type, $name);
    }

    /**
     * @return array<string, string>|array<string, array<string, string>>
     */
    public function getCoreComponents(null|string|BackedEnum $type = null): array
    {
        return resolve(ComponentRegistry::class)->getCoreComponents($type);
    }

    public function hasComponent(string|BackedEnum $type, string $name): bool
    {
        return resolve(ComponentRegistry::class)->hasComponent($type, $name);
    }

    public function registerDiscoverableComponents(string $in, ?string $for = null): static
    {
        resolve(ComponentRegistry::class)->registerDiscoverableComponents($in, $for);

        return $this;
    }

    public function discoverComponents(string $in, ?string $for = null): static
    {
        resolve(ComponentRegistry::class)->discoverComponents($in, $for);

        return $this;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getDiscoverableComponents(): array
    {
        return resolve(ComponentRegistry::class)->getDiscoverableComponents();
    }

    public function hasCachedComponents(): bool
    {
        return resolve(ComponentRegistry::class)->hasCachedComponents();
    }

    public function cacheComponents(): void
    {
        resolve(ComponentRegistry::class)->cacheComponents();
    }

    public function restoreCachedComponents(): void
    {
        resolve(ComponentRegistry::class)->restoreCachedComponents();
    }

    public function clearCachedComponents(): void
    {
        resolve(ComponentRegistry::class)->clearCachedComponents();
    }

    /** @internal */
    public function getComponentCachePath(): string
    {
        return resolve(ComponentRegistry::class)->getComponentCachePath();
    }
}
