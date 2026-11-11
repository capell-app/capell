<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Theme;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Merges a SiteSpec's theme settings into a theme, leaving settings the spec does not name as they were. */
final class MergeSiteSpecThemeSettingsAction
{
    use AsFake;
    use AsObject;

    public function handle(Theme $theme, CapellSiteSpecData $spec): Theme
    {
        [$meta, $customCss] = $this->merged($theme, $spec);
        $theme->meta = $meta;
        $theme->custom_css = $customCss;
        $theme->save();

        return $theme->refresh();
    }

    /** Whether merging would change the theme. */
    public function wouldChange(Theme $theme, CapellSiteSpecData $spec): bool
    {
        [$meta, $customCss] = $this->merged($theme, $spec);

        return $meta !== (is_array($theme->meta) ? $theme->meta : []) || $customCss !== $theme->custom_css;
    }

    /** @return array{0: array<array-key, mixed>, 1: string|null} */
    private function merged(Theme $theme, CapellSiteSpecData $spec): array
    {
        $meta = is_array($theme->meta) ? $theme->meta : [];
        $colors = array_filter($spec->theme->colors->toArray(), filled(...));

        return [
            array_merge($meta, $colors === [] ? [] : ['colors' => array_merge($meta['colors'] ?? [], $colors)], array_filter([
                'font_family' => $spec->theme->fontFamily,
                'link_color' => $spec->theme->linkColor,
                'link_color_active' => $spec->theme->linkColorActive,
                'container' => $spec->theme->container,
            ])),
            $spec->theme->customCss ?: $theme->custom_css,
        ];
    }
}
