<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Rendering;

use Capell\Core\ThemeStudio\Contracts\SectionRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemeRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Exceptions\SectionRendererNotFoundException;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Throwable;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class BladeThemeRenderer implements ThemeRenderer
{
    /**
     * @param  array<string, SectionRenderer>  $sectionRenderers
     */
    public function __construct(
        private readonly string $themeKey,
        private readonly string $layoutView,
        private readonly array $sectionRenderers,
    ) {}

    public function themeKey(): string
    {
        return $this->themeKey;
    }

    public function render(ThemePageData $page): string
    {
        $html = [];

        foreach ($page->allSections() as $section) {
            $html[] = $this->renderSection($section);
        }

        $content = implode("\n", $html);

        if (function_exists('view')) {
            try {
                /** @var view-string $layoutView */
                $layoutView = $this->layoutView;

                return view($layoutView, [
                    'brand' => $page->brand,
                    'content' => $content,
                    'page' => $page,
                    'themeKey' => $this->themeKey,
                ])->render();
            } catch (Throwable) {
                return $content;
            }
        }

        return $content;
    }

    private function renderSection(ThemeSection $section): string
    {
        $renderer = $this->resolveSectionRenderer($section->key());

        if (! $renderer instanceof SectionRenderer && $section->fallbackKey() !== null) {
            $renderer = $this->resolveSectionRenderer($section->fallbackKey());
        }

        if (! $renderer instanceof SectionRenderer) {
            throw SectionRendererNotFoundException::forSection($this->themeKey, $section->key());
        }

        return $renderer->render($section);
    }

    private function resolveSectionRenderer(string $sectionKey): ?SectionRenderer
    {
        if (function_exists('app') && app()->bound(ThemeRegistry::class)) {
            try {
                return resolve(ThemeRegistry::class)->sectionRenderer($this->themeKey, $sectionKey);
            } catch (Throwable) {
                return $this->sectionRenderers[$sectionKey] ?? null;
            }
        }

        return $this->sectionRenderers[$sectionKey] ?? null;
    }
}
