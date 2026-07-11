<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendRuntimeManifestContributor;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\FrontendRuntimeResolutionData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

class ResolveFrontendRuntimeAction
{
    use AsObject;

    public function __construct(
        private readonly Application $application,
    ) {}

    public function handle(FrontendContextReader $context): FrontendRuntimeResolutionData
    {
        $strategy = $this->renderingStrategy($context->page());
        $manifest = $this->runtimeManifest($strategy, $context);

        if ($strategy === RenderingStrategyEnum::FullLivewire) {
            return new FrontendRuntimeResolutionData(
                runtime: FrontendRuntime::Livewire,
                runtimeManifest: $manifest,
            );
        }

        $theme = $context->theme();

        if (! $theme instanceof Theme) {
            return new FrontendRuntimeResolutionData(
                runtime: FrontendRuntime::Blade,
                runtimeManifest: $manifest,
            );
        }

        $registry = resolve(ThemeRegistry::class);

        if (! $registry->has($theme->key)) {
            return new FrontendRuntimeResolutionData(
                runtime: FrontendRuntime::Blade,
                runtimeManifest: $manifest,
            );
        }

        $runtime = $registry->definition($theme->key)->runtime;

        if ($runtime === FrontendRuntime::Inertia) {
            $manifest->usesInertia = true;
            $manifest->modules['inertia'] = true;

            return new FrontendRuntimeResolutionData(
                runtime: $runtime,
                runtimeManifest: $manifest,
            );
        }

        return new FrontendRuntimeResolutionData(
            runtime: FrontendRuntime::Blade,
            runtimeManifest: $manifest,
        );
    }

    private function renderingStrategy(?Pageable $page): RenderingStrategyEnum
    {
        $blueprint = $page instanceof Model && $page->relationLoaded('blueprint') ? $page->blueprint : null;

        if ($blueprint?->is_livewire === true) {
            return RenderingStrategyEnum::FullLivewire;
        }

        return RenderingStrategyEnum::tryFrom((string) ($page?->meta['rendering_strategy'] ?? ''))
            ?? RenderingStrategyEnum::tryFrom((string) ($blueprint?->meta['rendering_strategy'] ?? ''))
            ?? RenderingStrategyEnum::BladeOnly;
    }

    private function runtimeManifest(RenderingStrategyEnum $strategy, FrontendContextReader $context): FrontendRuntimeManifestData
    {
        $manifest = FrontendRuntimeManifestData::forRenderingStrategy($strategy);
        $theme = $strategy === RenderingStrategyEnum::BladeOnly ? $context->theme() : null;

        if ($strategy === RenderingStrategyEnum::BladeOnly && $theme instanceof Theme) {
            $usesAlpine = $this->themeRuntimeFlag($theme, 'uses_alpine', true);
            $usesFrontendChrome = $this->themeRuntimeFlag($theme, 'uses_frontend_chrome', true);

            $manifest->usesAlpine = $usesAlpine;

            if ($usesFrontendChrome) {
                $manifest->modules['frontend-chrome'] = true;
            }
        }

        collect($this->application->tagged(FrontendRuntimeManifestContributor::TAG))
            ->filter(fn (mixed $contributor): bool => $contributor instanceof FrontendRuntimeManifestContributor)
            ->each(function (FrontendRuntimeManifestContributor $contributor) use ($context, $manifest): void {
                $contributor->contribute($context, $manifest);
            });

        if ($strategy === RenderingStrategyEnum::BladeOnly && $theme instanceof Theme) {
            if (! $this->themeRuntimeFlag($theme, 'uses_frontend_chrome', true)) {
                unset($manifest->modules['frontend-chrome']);
            }

            if (! $this->themeRuntimeFlag($theme, 'uses_alpine', true)
                && ! $manifest->usesLivewire
                && ! $manifest->usesIslands
                && ! $manifest->usesInertia) {
                $manifest->usesAlpine = false;
            }
        }

        return $manifest;
    }

    private function themeRuntimeFlag(Theme $theme, string $key, bool $default): bool
    {
        $value = data_get($theme->meta, 'frontend_runtime.' . $key);

        if (is_bool($value)) {
            return $value;
        }

        if (app()->bound(ThemeRegistry::class)) {
            $registry = resolve(ThemeRegistry::class);

            if ($registry->has($theme->key)) {
                $definitionValue = data_get($registry->definition($theme->key)->frontend, 'runtime.' . $key);

                if (is_bool($definitionValue)) {
                    return $definitionValue;
                }
            }
        }

        return $default;
    }
}
