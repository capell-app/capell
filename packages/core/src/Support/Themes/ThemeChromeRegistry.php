<?php

declare(strict_types=1);

namespace Capell\Core\Support\Themes;

final class ThemeChromeRegistry
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, string> */
    private array $footers = [];

    public function registerHeader(string $component, string $label): void
    {
        $this->headers[$component] = $label;
    }

    public function registerFooter(string $component, string $label): void
    {
        $this->footers[$component] = $label;
    }

    /**
     * @return array<string, string>
     */
    public function headerOptions(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, string>
     */
    public function footerOptions(): array
    {
        return $this->footers;
    }
}
