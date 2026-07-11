<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Rendering;

use Capell\Core\ThemeStudio\Contracts\SectionRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Throwable;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class ViewSectionRenderer implements SectionRenderer
{
    public function __construct(
        private readonly string $themeKey,
        private readonly string $sectionKey,
        private readonly string $view,
        private readonly bool $failLoudly = false,
        /** @var array<string, mixed> */
        private readonly array $extraViewData = [],
    ) {}

    public function themeKey(): string
    {
        return $this->themeKey;
    }

    public function sectionKey(): string
    {
        return $this->sectionKey;
    }

    public function render(ThemeSection $section): string
    {
        if (function_exists('view')) {
            try {
                /** @var view-string $view */
                $view = $this->view;

                return view($view, [
                    ...$section->toViewData(),
                    ...$this->extraViewData,
                ])->render();
            } catch (Throwable $throwable) {
                throw_if($this->failLoudly, $throwable);

                return $this->fallbackHtml($section);
            }
        }

        return $this->fallbackHtml($section);
    }

    private function fallbackHtml(ThemeSection $section): string
    {
        return '<section data-theme="' . $this->escape($this->themeKey) . '" data-section="' . $this->escape($section->key()) . '"></section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
