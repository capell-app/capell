<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Spatie\LaravelData\Data;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class ThemePageData extends Data
{
    /**
     * @param  array<int, ThemeSection>  $sections
     */
    public function __construct(
        public string $title,
        public BrandProfileData $brand,
        public array $sections = [],
        public ?NavigationData $navigation = null,
        public ?FooterData $footer = null,
    ) {}

    /**
     * @return array<int, ThemeSection>
     */
    public function allSections(): array
    {
        return array_values(array_filter([
            $this->navigation,
            ...$this->sections,
            $this->footer,
        ]));
    }
}
