<?php

declare(strict_types=1);

namespace Capell\Core\Support\Tailwind;

use Illuminate\Support\Collection;

/**
 * TailwindAssetsRegistry collects Tailwind CSS asset declarations across packages/providers.
 *
 * It stores four categories (imports, plugins, sources, theme_colors) with their originating context
 * and exposes de-duplicated, sorted values for CSS generation and a structured report for diagnostics.
 *
 * Contract:
 * - Register strings via register* methods (empty strings ignored).
 * - imports/plugins/sources are de-duplicated by string value and sorted lexicographically (first-wins).
 * - theme_colors are keyed by color name; later registrations override earlier ones (last-wins).
 * - Origins are kept for reporting via toReport().
 */
final class TailwindAssetsRegistry
{
    /** @var array<int, array{value: string, origin: string|null}> */
    private array $sources = [];

    /** @var array<int, array{value: string, origin: string|null}> */
    private array $imports = [];

    /** @var array<int, array{value: string, origin: string|null}> */
    private array $plugins = [];

    /** @var array<string, array{value: string, origin: string|null}> */
    private array $themeColors = [];

    public function registerSource(string $source, ?string $origin = null): self
    {
        $source = trim($source);

        if ($source !== '') {
            $this->sources[] = ['value' => $source, 'origin' => $origin];
        }

        return $this;
    }

    /** @param array<int, string> $sources */
    public function registerSources(array $sources, ?string $origin = null): self
    {
        foreach ($sources as $source) {
            if (is_string($source)) {
                $this->registerSource($source, $origin);
            }
        }

        return $this;
    }

    public function registerImport(string $import, ?string $origin = null): self
    {
        $import = trim($import);

        if ($import !== '') {
            $this->imports[] = ['value' => $import, 'origin' => $origin];
        }

        return $this;
    }

    /** @param array<int, string> $imports */
    public function registerImports(array $imports, ?string $origin = null): self
    {
        foreach ($imports as $import) {
            if (is_string($import)) {
                $this->registerImport($import, $origin);
            }
        }

        return $this;
    }

    public function registerPlugin(string $plugin, ?string $origin = null): self
    {
        $plugin = trim($plugin);

        if ($plugin !== '') {
            $this->plugins[] = ['value' => $plugin, 'origin' => $origin];
        }

        return $this;
    }

    /** @param array<int, string> $plugins */
    public function registerPlugins(array $plugins, ?string $origin = null): self
    {
        foreach ($plugins as $plugin) {
            if (is_string($plugin)) {
                $this->registerPlugin($plugin, $origin);
            }
        }

        return $this;
    }

    public function registerThemeColor(string $name, string $value, ?string $origin = null): self
    {
        $name = trim($name);
        $value = trim($value);

        if ($name !== '' && $value !== '') {
            $this->themeColors[$name] = ['value' => $value, 'origin' => $origin];
        }

        return $this;
    }

    /** @param array<string, string> $colors */
    public function registerThemeColors(array $colors, ?string $origin = null): self
    {
        foreach ($colors as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $this->registerThemeColor($name, $value, $origin);
            }
        }

        return $this;
    }

    /** @return Collection<int, non-empty-string> */
    public function sources(): Collection
    {
        return $this->uniqueSortedValues($this->sources);
    }

    /** @return Collection<int, non-empty-string> */
    public function imports(): Collection
    {
        return $this->uniqueSortedValues($this->imports);
    }

    /** @return Collection<int, non-empty-string> */
    public function plugins(): Collection
    {
        return $this->uniqueSortedValues($this->plugins);
    }

    /**
     * Returns theme colors sorted by name, keyed by CSS variable name.
     *
     * @return Collection<string, string>
     */
    public function themeColors(): Collection
    {
        return collect($this->themeColors)
            ->map(fn (array $entry): string => $entry['value'])
            ->sortKeys();
    }

    public function hasThemeColors(): bool
    {
        return $this->themeColors !== [];
    }

    /** @return array<string, array<int, array{value: string, origin: string|null}>|array<string, array{value: string, origin: string|null}>> */
    public function toReport(): array
    {
        return [
            'imports' => $this->uniqueSortedEntries($this->imports),
            'plugins' => $this->uniqueSortedEntries($this->plugins),
            'sources' => $this->uniqueSortedEntries($this->sources),
            'theme_colors' => $this->themeColors,
        ];
    }

    /**
     * @param  array<int, array{value: string, origin: string|null}>  $entries
     * @return Collection<int, non-empty-string>
     */
    private function uniqueSortedValues(array $entries): Collection
    {
        return collect($entries)
            ->filter(fn (array $entry): bool => $entry['value'] !== '')
            ->unique('value')
            ->sortBy('value')
            ->values()
            ->map(fn (array $entry): string => $entry['value']);
    }

    /** @param array<int, array{value: string, origin: string|null}> $entries
     * @return array<int, array{value: string, origin: string|null}>
     */
    private function uniqueSortedEntries(array $entries): array
    {
        return collect($entries)
            ->filter(fn (array $entry): bool => $entry['value'] !== '')
            ->unique('value')
            ->sortBy('value')
            ->values()
            ->all();
    }
}
