<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Contracts\PagePresentation;

final class PagePresentationRegistry
{
    /** @var array<string, array<string, array<string, PagePresentation>>> */
    private array $presentations = [];

    public function register(PagePresentation $presentation, ?string $themeKey = null): void
    {
        $this->presentations[$presentation->pageType()][$presentation->runtime()->value][$this->scope($themeKey)] = $presentation;
    }

    public function get(string $pageType, FrontendRuntime $runtime, ?string $themeKey = null): ?PagePresentation
    {
        return $this->presentations[$pageType][$runtime->value][$this->scope($themeKey)]
            ?? $this->presentations[$pageType][$runtime->value][$this->scope(null)]
            ?? null;
    }

    public function has(string $pageType, FrontendRuntime $runtime, ?string $themeKey = null): bool
    {
        return $this->get($pageType, $runtime, $themeKey) instanceof PagePresentation;
    }

    private function scope(?string $themeKey): string
    {
        return $themeKey ?? '*';
    }
}
