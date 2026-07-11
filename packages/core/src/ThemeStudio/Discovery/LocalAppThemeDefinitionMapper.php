<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Discovery;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use InvalidArgumentException;

final class LocalAppThemeDefinitionMapper
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function fromManifest(array $manifest): ThemeDefinitionData
    {
        foreach (['key', 'name', 'description', 'package', 'previewImage'] as $field) {
            if (! is_string($manifest[$field] ?? null) || trim($manifest[$field]) === '') {
                throw new InvalidArgumentException(sprintf('Theme manifest is missing required string field [%s].', $field));
            }
        }

        $presets = $manifest['presets'] ?? [];

        throw_if(! is_array($presets) || $presets === [], InvalidArgumentException::class, 'Theme manifest must include at least one preset.');

        return new ThemeDefinitionData(
            key: $manifest['key'],
            name: $manifest['name'],
            description: $manifest['description'],
            package: $manifest['package'],
            previewImage: $manifest['previewImage'],
            tags: $this->stringList($manifest['tags'] ?? []),
            bestFit: $this->stringList($manifest['bestFit'] ?? []),
            presets: $this->presets($presets),
            includedSections: $this->stringList($manifest['includedSections'] ?? []),
            assets: $this->stringArray($manifest['assets'] ?? []),
            runtime: $this->runtime($manifest['runtime'] ?? null),
            frontend: is_array($manifest['frontend'] ?? null) ? $manifest['frontend'] : [],
            extends: $this->optionalString($manifest['extends'] ?? null),
        );
    }

    /**
     * @param  array<int, mixed>  $presets
     * @return array<int, ThemePresetData>
     */
    private function presets(array $presets): array
    {
        return collect($presets)
            ->map(function (mixed $preset): ThemePresetData {
                throw_unless(is_array($preset), InvalidArgumentException::class, 'Theme preset must be an object.');

                foreach (['key', 'name', 'description', 'previewImage'] as $field) {
                    if (! is_string($preset[$field] ?? null) || trim($preset[$field]) === '') {
                        throw new InvalidArgumentException(sprintf('Theme preset is missing required string field [%s].', $field));
                    }
                }

                return new ThemePresetData(
                    key: $preset['key'],
                    name: $preset['name'],
                    description: $preset['description'],
                    previewImage: $preset['previewImage'],
                    values: is_array($preset['values'] ?? null) ? $preset['values'] : [],
                );
            })
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->values()
            ->all();
    }

    /** @return array<array-key, string> */
    private function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->all();
    }

    private function runtime(mixed $value): FrontendRuntime
    {
        if (! is_string($value) || $value === '') {
            return FrontendRuntime::Livewire;
        }

        return FrontendRuntime::tryFrom($value)
            ?? throw new InvalidArgumentException(sprintf('Theme manifest has invalid runtime [%s].', $value));
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
