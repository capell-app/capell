<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Models\Theme;
use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use Capell\Frontend\Data\Assets\ThemeResourceGroupData;
use Capell\Frontend\Support\View\PublicModelMeta;

class ThemeResourceResolver
{
    public function __construct(
        private readonly ?FrontendResourceRegistry $registry = null,
    ) {}

    public function group(?Theme $theme, string $key): ?FrontendResourceGroupData
    {
        $group = $this->groups($theme)[$key] ?? null;

        return $group instanceof FrontendResourceGroupData ? $group : null;
    }

    /**
     * @return array<string, FrontendResourceGroupData>
     */
    public function groups(?Theme $theme): array
    {
        $groups = $this->packageGroups();

        if (! $theme instanceof Theme) {
            return $groups;
        }

        $themeGroups = $this->themeGroups($theme);

        return array_replace($groups, $themeGroups);
    }

    /**
     * @return array<string, FrontendResourceGroupData>
     */
    private function packageGroups(): array
    {
        $registry = $this->registry ?? (app()->bound(FrontendResourceRegistry::class) ? resolve(FrontendResourceRegistry::class) : null);

        return $registry instanceof FrontendResourceRegistry ? $registry->all() : [];
    }

    /**
     * @return array<string, FrontendResourceGroupData>
     */
    private function themeGroups(Theme $theme): array
    {
        $definitions = PublicModelMeta::get($theme, 'editor.resources');
        if (! is_array($definitions)) {
            $definitions = PublicModelMeta::get($theme, 'resources', []);
        }

        if (! is_array($definitions) || $definitions === []) {
            return [];
        }

        $defaultBuildPath = PublicModelMeta::get($theme, 'editor.resources_build_path');
        if (! is_string($defaultBuildPath) || $defaultBuildPath === '') {
            $defaultBuildPath = PublicModelMeta::get($theme, 'assets_path', 'build');
        }

        if (! is_string($defaultBuildPath) || $defaultBuildPath === '') {
            $defaultBuildPath = 'build';
        }

        return collect($definitions)
            ->map(fn (mixed $definition, string|int $key): ?ThemeResourceGroupData => ThemeResourceGroupData::fromDefinition($key, $definition, $defaultBuildPath))
            ->filter(fn (?ThemeResourceGroupData $group): bool => $group instanceof ThemeResourceGroupData)
            ->mapWithKeys(fn (ThemeResourceGroupData $group): array => [$group->key => $group->toFrontendResourceGroup('theme')])
            ->all();
    }
}
