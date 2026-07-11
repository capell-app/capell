<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\ThemeStudio\Contracts\SectionRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemeRenderer;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Exceptions\ThemeNotFoundException;

class ThemeRegistry
{
    /** @var array<string, ThemeDefinitionData> */
    private array $definitions = [];

    /** @var array<string, ThemeRenderer> */
    private array $themeRenderers = [];

    /** @var array<string, array<string, SectionRenderer>> */
    private array $sectionRenderers = [];

    /**
     * @param  array<int, SectionRenderer>  $sectionRenderers
     */
    public function register(
        ThemeDefinitionData $definition,
        ?ThemeRenderer $themeRenderer = null,
        array $sectionRenderers = [],
    ): void {
        $this->definitions[$definition->key] = $definition;
        $this->sectionRenderers[$definition->key] = [];

        if ($themeRenderer instanceof ThemeRenderer) {
            $this->themeRenderers[$definition->key] = $themeRenderer;
        } else {
            unset($this->themeRenderers[$definition->key]);
        }

        foreach ($sectionRenderers as $sectionRenderer) {
            $this->sectionRenderers[$definition->key][$sectionRenderer->sectionKey()] = $sectionRenderer;
        }
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

    public function renderer(string $themeKey): ThemeRenderer
    {
        return $this->themeRenderers[$themeKey] ?? throw ThemeNotFoundException::forKey($themeKey);
    }

    /**
     * Whether the theme registered a {@see ThemeRenderer}. Definition-only
     * themes (rendered through x-capell::layout + layout-builder instead of
     * the section-rendering pipeline) have no entry here — callers must gate
     * on this before calling {@see self::renderer()}.
     */
    public function hasRenderer(string $themeKey): bool
    {
        return isset($this->themeRenderers[$themeKey]);
    }

    /**
     * Nullable convenience accessor for callers that want the renderer when
     * present and a plain `null` otherwise, without duplicating the
     * {@see self::hasRenderer()} / {@see self::renderer()} ternary at every
     * call site.
     */
    public function findRenderer(string $themeKey): ?ThemeRenderer
    {
        return $this->themeRenderers[$themeKey] ?? null;
    }

    /**
     * Resolve a renderer from the theme itself or its parent chain.
     *
     * Definition-only themes use Layout Builder for newly-authored pages, but
     * an inherited renderer provides a lossless fallback for legacy pages that
     * have not acquired containers yet. Callers must still give authored
     * containers precedence over this compatibility path.
     */
    public function findRendererInChain(string $themeKey): ?ThemeRenderer
    {
        $definition = $this->definitions[$themeKey] ?? null;

        if (! $definition instanceof ThemeDefinitionData) {
            return null;
        }

        return $this->rendererInChain($definition, []);
    }

    public function sectionRenderer(string $themeKey, string $sectionKey): ?SectionRenderer
    {
        $definition = $this->definitions[$themeKey] ?? throw ThemeNotFoundException::forKey($themeKey);

        return $this->sectionRendererInChain($definition, $sectionKey, []);
    }

    public function has(string $themeKey): bool
    {
        return isset($this->definitions[$themeKey]);
    }

    public function reset(): void
    {
        $this->definitions = [];
        $this->themeRenderers = [];
        $this->sectionRenderers = [];
    }

    /**
     * @param  array<int, string>  $visitedThemeKeys
     */
    private function sectionRendererInChain(
        ThemeDefinitionData $definition,
        string $sectionKey,
        array $visitedThemeKeys,
    ): ?SectionRenderer {
        if (in_array($definition->key, $visitedThemeKeys, true)) {
            return null;
        }

        $renderer = $this->sectionRenderers[$definition->key][$sectionKey] ?? null;

        if ($renderer !== null || $definition->extends === null) {
            return $renderer;
        }

        $parentDefinition = $this->definitions[$definition->extends] ?? null;

        if ($parentDefinition === null) {
            return null;
        }

        return $this->sectionRendererInChain(
            definition: $parentDefinition,
            sectionKey: $sectionKey,
            visitedThemeKeys: [...$visitedThemeKeys, $definition->key],
        );
    }

    /**
     * @param  list<string>  $visitedThemeKeys
     */
    private function rendererInChain(ThemeDefinitionData $definition, array $visitedThemeKeys): ?ThemeRenderer
    {
        if (in_array($definition->key, $visitedThemeKeys, true)) {
            return null;
        }

        $renderer = $this->themeRenderers[$definition->key] ?? null;

        if ($renderer instanceof ThemeRenderer || $definition->extends === null) {
            return $renderer;
        }

        $parentDefinition = $this->definitions[$definition->extends] ?? null;

        if (! $parentDefinition instanceof ThemeDefinitionData) {
            return null;
        }

        return $this->rendererInChain(
            definition: $parentDefinition,
            visitedThemeKeys: [...$visitedThemeKeys, $definition->key],
        );
    }
}
