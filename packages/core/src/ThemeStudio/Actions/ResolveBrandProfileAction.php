<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Actions;

use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeOverrideData;

class ResolveBrandProfileAction
{
    public static function run(
        BrandProfileData $brand,
        ThemeDefinitionData $definition,
        ThemeOverrideData $override,
    ): BrandProfileData {
        return (new self)->handle($brand, $definition, $override);
    }

    public function handle(
        BrandProfileData $brand,
        ThemeDefinitionData $definition,
        ThemeOverrideData $override,
    ): BrandProfileData {
        $presetValues = [];

        if ($override->presetKey !== null) {
            $preset = $definition->preset($override->presetKey);
            $presetValues = $preset?->values ?? [];
        }

        $resolved = $brand
            ->merge($presetValues)
            ->merge($override->values);

        return $resolved->withCustomTokens($this->customTokens(
            $definition,
            [...$presetValues, ...$override->values],
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function customTokens(ThemeDefinitionData $definition, array $values): array
    {
        $declarations = data_get($definition->frontend, 'editor.tokens', []);

        if (! is_array($declarations)) {
            return [];
        }

        $tokens = [];

        foreach ($declarations as $key => $declaration) {
            if (! is_string($key)) {
                continue;
            }
            if (BrandProfileData::supportsToken($key)) {
                continue;
            }
            if (! BrandProfileData::supportsCustomToken($key)) {
                continue;
            }

            if (! is_array($declaration)) {
                continue;
            }

            $options = $declaration['options'] ?? null;
            $value = $values[$key] ?? null;
            if (! is_array($options)) {
                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            if (! in_array($value, $options, true)) {
                continue;
            }

            $tokens[$key] = $value;
        }

        return $tokens;
    }
}
