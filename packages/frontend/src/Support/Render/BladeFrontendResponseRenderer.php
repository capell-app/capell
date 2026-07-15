<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Layout;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Frontend\Actions\AssertPublicRenderContractAction;
use Capell\Frontend\Actions\RenderPageRecordDataAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendResourcePlanRenderer;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendResourceContextData;
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
                    'resourcePlan' => $context->publicRenderData?->resourcePlan,
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
            $this->primeRenderedFrontendResources($context, $runtimeManifest);

            return;
        }

        $settings = App::make(ThemeRuntimeSettings::class);

        $settings->activeTheme();
        $settings->activePreset();
        $settings->brandProfile();
        $settings->themeOverrides();

        $this->primeRenderedFrontendResources($context, $runtimeManifest);
    }

    private function primeRenderedFrontendResources(FrontendRenderContextData $context, FrontendRuntimeManifestData $runtimeManifest): void
    {
        if (! App::bound(FrontendContextReader::class) || ! App::bound(FrontendResourcePlanRenderer::class)) {
            return;
        }

        $resourcePlan = $context->publicRenderData?->resourcePlan;

        if (! $resourcePlan instanceof FrontendResourcePlanData) {
            return;
        }

        App::make(FrontendContextReader::class)->setFrontendData(
            'renderedFrontendResources',
            App::make(FrontendResourcePlanRenderer::class)->render($resourcePlan, new FrontendResourceContextData(
                page: $context->page,
                site: $context->site,
                language: $context->language,
                layout: $context->layout,
                theme: $context->theme,
                runtime: $runtimeManifest,
            )),
        );
    }

    private function layoutHasContainers(?Layout $layout): bool
    {
        $containers = $layout?->getAttribute('containers');

        return is_array($containers) && $containers !== [];
    }
}
