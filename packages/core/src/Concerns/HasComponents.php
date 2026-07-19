<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use BackedEnum;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Throwable;

trait HasComponents
{
    /** @var array<string, array<string, string>> */
    protected array $components = [];

    /** @var array<string, array<string, string>>|null */
    protected ?array $discoveredComponents = null;

    /** @var array<string, string|null> */
    protected array $componentsNamespaces = [];

    protected ?bool $hasCachedComponents = null;

    public static function getComponentTypeFromDirectory(string $directory): string
    {
        return str($directory)
            ->afterLast(DIRECTORY_SEPARATOR)
            ->replace(['-', '_'], ' ')
            ->studly()
            ->toString();
    }

    public function registerComponent(string|BackedEnum $type, string|BackedEnum $name, string $component): static
    {
        if ($type instanceof BackedEnum) {
            $type = $type->name;
        }

        if ($name instanceof BackedEnum) {
            $name = $name->name;
        }

        if (! isset($this->components[$type])) {
            $this->components[$type] = [];
        }

        if (! isset($this->components[$type][$name])) {
            $this->components[$type][$name] = $component;
        }

        return $this;
    }

    /**
     * @param  array<int|string, string|BackedEnum>  $components
     */
    public function registerComponents(string|BackedEnum $type, array $components): static
    {
        foreach ($components as $name => $component) {
            if ($component instanceof BackedEnum) {
                $name = $component->name;
                $component = $component->value;
            }

            if (! is_string($component)) {
                continue;
            }

            $this->registerComponent($type, (string) $name, $component);
        }

        return $this;
    }

    /**
     * @return array<string, string>|array<string, array<string, string>>
     */
    public function getComponents(null|string|BackedEnum $type = null): array
    {
        if ($type === null) {
            $merged = array_merge_recursive($this->components, $this->getDiscoverableComponents());

            array_walk($merged, fn (array &$c): bool => ksort($c));

            ksort($merged);

            return $merged;
        }

        if ($type instanceof BackedEnum) {
            $type = $type->name;
        }

        $components = array_merge(
            $this->components[$type] ?? [],
            $this->getDiscoverableComponents()[$type] ?? [],
        );

        ksort($components);

        return $components;
    }

    public function getComponent(string|BackedEnum $type, string $name): string
    {
        if ($type instanceof BackedEnum) {
            $type = $type->name;
        }

        if (isset($this->getDiscoverableComponents()[$type][$name])) {
            return $this->getDiscoverableComponents()[$type][$name];
        }

        throw_unless(isset($this->components[$type][$name]), InvalidArgumentException::class, sprintf('Component with type %s and name %s not found.', $type, $name));

        return $this->components[$type][$name];
    }

    /**
     * @return array<string, string>|array<string, array<string, string>>
     */
    public function getCoreComponents(null|string|BackedEnum $type = null): array
    {
        if ($type === null) {
            return $this->components;
        }

        if ($type instanceof BackedEnum) {
            $type = $type->name;
        }

        throw_unless(isset($this->components[$type]), InvalidArgumentException::class, sprintf('Component type %s not found.', $type));

        return $this->components[$type];
    }

    public function hasComponent(string|BackedEnum $type, string $name): bool
    {
        if ($type instanceof BackedEnum) {
            $type = $type->name;
        }

        if (isset($this->getDiscoverableComponents()[$type][$name])) {
            return true;
        }

        return isset($this->components[$type][$name]);
    }

    public function registerDiscoverableComponents(string $in, ?string $for = null): static
    {
        $this->componentsNamespaces[$in] = $for;

        return $this;
    }

    public function discoverComponents(string $in, ?string $for = null): static
    {
        if ($this->hasCachedComponents()) {
            return $this;
        }

        $this->discoverTypes(directory: $in, for: $for);

        return $this;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getDiscoverableComponents(): array
    {
        if ($this->discoveredComponents !== null) {
            return $this->discoveredComponents;
        }

        $this->discoveredComponents = [];

        foreach ($this->componentsNamespaces as $in => $for) {
            $this->discoverTypes(
                directory: $in,
                for: $for,
            );
        }

        return $this->discoveredComponents ?? [];
    }

    public function hasCachedComponents(): bool
    {
        return $this->hasCachedComponents ??= ((! app()->runningInConsole()) && resolve(Filesystem::class)->exists($this->getComponentCachePath()));
    }

    public function cacheComponents(): void
    {
        $this->hasCachedComponents = false;

        $cachePath = $this->getComponentCachePath();

        $filesystem = resolve(Filesystem::class);

        $filesystem->ensureDirectoryExists((string) str($cachePath)->beforeLast(DIRECTORY_SEPARATOR));

        $filesystem->put(
            $cachePath,
            '<?php return ' . var_export([
                'components' => $this->components,
                'discoveredComponents' => $this->getDiscoverableComponents(),
            ], true) . ';',
        );

        $this->hasCachedComponents = true;
    }

    public function restoreCachedComponents(): void
    {
        if (! $this->hasCachedComponents()) {
            return;
        }

        $cache = require $this->getComponentCachePath();

        $this->components = is_array($cache['components'] ?? null) ? $cache['components'] : [];
        $this->discoveredComponents = is_array($cache['discoveredComponents'] ?? null) ? $cache['discoveredComponents'] : [];
    }

    public function clearCachedComponents(): void
    {
        resolve(Filesystem::class)->delete($this->getComponentCachePath());

        $this->hasCachedComponents = false;

        $this->clearCachedFilamentComponents();
    }

    /** @internal */
    public function getComponentCachePath(): string
    {
        return config('capell.cache_path', base_path('bootstrap/cache/capell')) . DIRECTORY_SEPARATOR . 'components.php';
    }

    protected function flushComponentRuntimeState(): void
    {
        $this->discoveredComponents = null;
        $this->hasCachedComponents = null;
    }

    protected function discoverBladeFiles(string $type, ?string $for, string $directory): void
    {
        if (blank($directory) || blank($type)) {
            return;
        }

        $filesystem = resolve(Filesystem::class);

        if ((! $filesystem->exists($directory)) && (! str($directory)->contains('*'))) {
            return;
        }

        $bladeFiles = $filesystem->files($directory);

        foreach ($bladeFiles as $file) {
            if (! str($file->getFilename())->endsWith('.blade.php')) {
                continue;
            }

            $name = in_array($for, [null, '', '0'], true) ? '' : $for . '.';
            $name .= str($file->getFilename())->before('.blade.php')->toString();

            $this->discoveredComponents[$type][$name] = $name;
        }
    }

    protected function discoverTypes(string $directory, ?string $for = null): void
    {
        if (blank($directory)) {
            return;
        }

        $filesystem = resolve(Filesystem::class);

        if ((! $filesystem->exists($directory)) && (! str($directory)->contains('*'))) {
            return;
        }

        foreach ($filesystem->directories($directory) as $subDirectory) {
            $type = self::getComponentTypeFromDirectory($subDirectory);

            $this->discoverBladeFiles($type, $for, $subDirectory);
        }
    }

    private function clearCachedFilamentComponents(): void
    {
        resolve(Filesystem::class)->deleteDirectory(base_path('bootstrap/cache/filament'));

        try {
            Artisan::call('filament:clear-cached-components');
        } catch (Throwable) {
            // Cache cleanup is best-effort because Filament may not be registered in every host application.
        }
    }
}
