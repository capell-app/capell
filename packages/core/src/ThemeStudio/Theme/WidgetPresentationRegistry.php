<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Contracts\WidgetPresentation;

final class WidgetPresentationRegistry
{
    /** @var array<string, array<string, array<string, WidgetPresentation>>> */
    private array $presentations = [];

    public function register(WidgetPresentation $presentation, ?string $themeKey = null): void
    {
        $this->presentations[$presentation->widgetType()][$presentation->runtime()->value][$this->scope($themeKey)] = $presentation;
    }

    public function get(string $widgetType, FrontendRuntime $runtime, ?string $themeKey = null): ?WidgetPresentation
    {
        return $this->presentations[$widgetType][$runtime->value][$this->scope($themeKey)]
            ?? $this->presentations[$widgetType][$runtime->value][$this->scope(null)]
            ?? null;
    }

    public function has(string $widgetType, FrontendRuntime $runtime, ?string $themeKey = null): bool
    {
        return $this->get($widgetType, $runtime, $themeKey) instanceof WidgetPresentation;
    }

    private function scope(?string $themeKey): string
    {
        return $themeKey ?? '*';
    }
}
