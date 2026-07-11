<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Capell\Core\ThemeStudio\Actions\RenderCurrentThemePageAction;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Actions\AssertPublicRenderContractAction;
use Capell\Frontend\Actions\RenderPageRecordDataAction;
use Capell\Frontend\Contracts\FrontendAssetManifestRenderer;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\Response;

final class BladeFrontendResponseRenderer implements FrontendResponseRenderer
{
    public function runtime(): FrontendRuntime
    {
        return FrontendRuntime::Blade;
    }

    public function render(FrontendRenderContextData $context): Response|Responsable
    {
        if (App::bound('capell.frontend.page-markdown-response')) {
            $markdownResponse = App::make('capell.frontend.page-markdown-response')();

            if ($markdownResponse instanceof Response) {
                if ($context->status !== null) {
                    $markdownResponse->setStatusCode($context->status);
                }

                AssertPublicRenderContractAction::run($markdownResponse);

                return $markdownResponse;
            }
        }

        $page = $context->page;

        if (! $page instanceof Pageable) {
            return response()->noContent($context->status ?? 404);
        }

        $runtimeManifest = $context->runtimeManifest
            ?? FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);

        RenderPageRecordDataAction::run($page, []);
        $this->primePublicRenderDependencies($context, $runtimeManifest);

        $response = resolve(PublicViewQueryGuard::class)->guard($context, function () use ($context, $page, $runtimeManifest): Response {
            $slot = $this->hydratedLayoutGraphSlot($context)
                ?? $this->themeSlot($context)
                ?? $this->layoutBuilderSlot($context)
                ?? View::make($this->masterFile($context->layout), [
                    'componentName' => 'capell.frontend.page',
                    'params' => [],
                ])->render();

            return response()->view(
                $this->layoutFile($context->layout),
                [
                    'componentName' => 'capell.frontend.page',
                    'language' => $context->language,
                    'layout' => $context->layout,
                    'livewireEnabled' => $runtimeManifest->usesLivewire,
                    'params' => [],
                    'pageRecord' => $page,
                    'assetManifest' => $context->publicRenderData?->assetManifest,
                    'publicRenderData' => $context->publicRenderData,
                    'runtimeManifest' => $runtimeManifest,
                    'site' => $context->site,
                    'slot' => new HtmlString($slot),
                    'theme' => $context->theme,
                ],
                $context->status ?? 200,
            );
        });

        AssertPublicRenderContractAction::run($response);

        return $response;
    }

    private function layoutFile(?Layout $layout): string
    {
        return $layout?->meta['layout_file'] ?? config('capell-frontend.layout_file', 'capell::app');
    }

    private function masterFile(?Layout $layout): string
    {
        return $layout?->meta['master_file'] ?? 'capell::livewire.page.page';
    }

    private function layoutBuilderSlot(FrontendRenderContextData $context): ?string
    {
        if (! $this->layoutHasContainers($context->layout)) {
            return null;
        }

        return Blade::render('<x-capell::layout />', [
            '__env' => resolve(Factory::class),
        ]);
    }

    private function hydratedLayoutGraphSlot(FrontendRenderContextData $context): ?string
    {
        $layoutGraph = $context->publicRenderData?->layoutGraph;

        if (! is_object($layoutGraph)) {
            return null;
        }

        $sections = collect(data_get($layoutGraph, 'containers', []))
            ->flatMap(
                fn (mixed $container): array => collect(data_get($container, 'widgets', []))
                    ->flatMap(
                        fn (mixed $widget): array => collect(data_get($widget, 'data.sections', []))
                            ->sortBy(static fn (mixed $section): int => is_array($section) && is_numeric($section['order'] ?? null) ? (int) $section['order'] : PHP_INT_MAX)
                            ->values()
                            ->all(),
                    )
                    ->all(),
            )
            ->filter(fn (mixed $section): bool => is_array($section) && is_string($section['html'] ?? null) && trim($section['html']) !== '')
            ->map(fn (array $section): string => (string) $section['html'])
            ->values();

        if ($sections->isEmpty()) {
            return null;
        }

        return $sections->implode("\n");
    }

    private function primePublicRenderDependencies(FrontendRenderContextData $context, FrontendRuntimeManifestData $runtimeManifest): void
    {
        resolve(FrontendSettingsReaderInterface::class)->minifyHtml();
        resolve(ImageUrlPolicy::class)->allowedDomains();
        resolve(ImageUrlPolicy::class)->allowsRelativeUrls();

        if (! App::bound(ThemeRuntimeSettings::class)) {
            $this->primeAssetManifestHtml($context, $runtimeManifest);

            return;
        }

        $settings = App::make(ThemeRuntimeSettings::class);

        $settings->activeTheme();
        $settings->activePreset();
        $settings->brandProfile();
        $settings->themeOverrides();

        $this->primeAssetManifestHtml($context, $runtimeManifest);
    }

    private function primeAssetManifestHtml(FrontendRenderContextData $context, FrontendRuntimeManifestData $runtimeManifest): void
    {
        if (! App::bound(FrontendContextReader::class) || ! App::bound(FrontendAssetManifestRenderer::class)) {
            return;
        }

        $assetManifest = $context->publicRenderData?->assetManifest;

        if (! $assetManifest instanceof FrontendAssetManifestData) {
            return;
        }

        App::make(FrontendContextReader::class)->setFrontendData(
            'assetManifestHtml',
            App::make(FrontendAssetManifestRenderer::class)->render($assetManifest, new FrontendAssetContextData(
                page: $context->page,
                site: $context->site,
                language: $context->language,
                layout: $context->layout,
                theme: $context->theme,
                runtime: $runtimeManifest,
            )),
        );
    }

    private function themeSlot(FrontendRenderContextData $context): ?string
    {
        if ($this->layoutHasContainers($context->layout)) {
            return null;
        }

        if (! App::bound(ThemeRegistry::class) || ! App::bound(ThemeRuntimeSettings::class)) {
            return null;
        }

        $themeKey = $this->themeKey($context);

        if ($themeKey === null) {
            return null;
        }

        $registry = App::make(ThemeRegistry::class);

        if (! $registry->hasRenderer($themeKey) || $registry->definition($themeKey)->runtime !== FrontendRuntime::Blade) {
            return null;
        }

        return RenderCurrentThemePageAction::run(
            activeTheme: $themeKey,
            activePreset: $this->activePreset($context),
        );
    }

    private function layoutHasContainers(?Layout $layout): bool
    {
        $containers = $layout?->getAttribute('containers');

        return is_array($containers) && $containers !== [];
    }

    private function themeKey(FrontendRenderContextData $context): ?string
    {
        if ($context->theme instanceof Theme && $context->theme->key !== '') {
            return $context->theme->key;
        }

        $activeTheme = App::make(ThemeRuntimeSettings::class)->activeTheme();

        return $activeTheme !== '' ? $activeTheme : null;
    }

    private function activePreset(FrontendRenderContextData $context): string
    {
        $settings = App::make(ThemeRuntimeSettings::class);

        if (! $context->theme instanceof Theme) {
            return $settings->activePreset();
        }

        $activePreset = data_get(
            $context->theme->meta,
            'editor.preset.active',
            data_get($context->theme->meta, 'active_preset', $settings->activePreset()),
        );

        return is_string($activePreset) && $activePreset !== ''
            ? $activePreset
            : $settings->activePreset();
    }
}
