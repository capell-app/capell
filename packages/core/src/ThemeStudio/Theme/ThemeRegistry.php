<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Exceptions\ThemeNotFoundException;

/** @extends AbstractKeyedRegistry<ThemeDefinitionData> */
class ThemeRegistry extends AbstractKeyedRegistry
{
    public function register(ThemeDefinitionData $definition): void
    {
        $this->setItem($definition->key, $definition);
    }

    /**
     * @return array<string, ThemeDefinitionData>
     */
    public function definitions(): array
    {
        $definitions = $this->allItems();
        ksort($definitions);

        return $definitions;
    }

    public function definition(string $themeKey): ThemeDefinitionData
    {
        return $this->getItem($themeKey) ?? throw ThemeNotFoundException::forKey($themeKey);
    }

    public function has(string $themeKey): bool
    {
        return $this->hasItem($themeKey);
    }
}
