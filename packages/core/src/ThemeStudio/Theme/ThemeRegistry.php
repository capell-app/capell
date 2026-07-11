<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Exceptions\ThemeNotFoundException;

class ThemeRegistry
{
    /** @var array<string, ThemeDefinitionData> */
    private array $definitions = [];

    public function register(ThemeDefinitionData $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    /**
     * @return array<string, ThemeDefinitionData>
     */
    public function definitions(): array
    {
        ksort($this->definitions);

        return $this->definitions;
    }

    public function definition(string $themeKey): ThemeDefinitionData
    {
        return $this->definitions[$themeKey] ?? throw ThemeNotFoundException::forKey($themeKey);
    }

    public function has(string $themeKey): bool
    {
        return isset($this->definitions[$themeKey]);
    }

    public function reset(): void
    {
        $this->definitions = [];
    }
}
