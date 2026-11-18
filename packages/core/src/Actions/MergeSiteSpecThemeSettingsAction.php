<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanChangeData;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Arr;
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

    /**
     * Each theme setting merging would change, with its current and new value.
     * Nested settings are named by their path, such as `colors.primary`.
     *
     * @return list<SiteSpecPlanChangeData>
     */
    public function differences(Theme $theme, CapellSiteSpecData $spec): array
    {
        [$meta, $customCss] = $this->merged($theme, $spec);
        $before = Arr::dot(is_array($theme->meta) ? $theme->meta : []);
        $after = Arr::dot($meta);
        $differences = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $differences[] = new SiteSpecPlanChangeData((string) $field, $this->stringValue($before[$field] ?? null), $this->stringValue($value));
            }
        }

        if ($customCss !== $theme->custom_css) {
            $differences[] = new SiteSpecPlanChangeData('custom_css', $theme->custom_css, $customCss);
        }

        return $differences;
    }

    private function stringValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            is_scalar($value) => var_export($value, true),
            is_array($value) => JsonCodec::encode($value),
            default => get_debug_type($value),
        };
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
