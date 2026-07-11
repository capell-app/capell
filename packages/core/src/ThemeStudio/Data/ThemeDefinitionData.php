<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Exceptions\ThemePresetNotFoundException;
use Spatie\LaravelData\Data;

class ThemeDefinitionData extends Data
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $bestFit
     * @param  array<int, ThemePresetData>  $presets
     * @param  array<int, string>  $includedSections
     * @param  array<string, string>  $assets
     * @param  array<string, mixed>  $frontend
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $package,
        public string $previewImage,
        public array $tags,
        public array $bestFit,
        public array $presets,
        public array $includedSections = [],
        public array $assets = [],
        public FrontendRuntime $runtime = FrontendRuntime::Livewire,
        public array $frontend = [],
        public ?string $extends = null,
    ) {}

    public function preset(string $key): ?ThemePresetData
    {
        foreach ($this->presets as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    public function presetOrFail(string $key): ThemePresetData
    {
        return $this->preset($key) ?? throw ThemePresetNotFoundException::forKey($this->key, $key);
    }

    /**
     * @return array<string, string>
     */
    public function presetOptions(): array
    {
        return collect($this->presets)
            ->mapWithKeys(fn (ThemePresetData $preset): array => [$preset->key => $preset->name])
            ->all();
    }
}
