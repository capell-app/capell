<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\SiteSpecExportData;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellSiteSpecConstraints;
use Capell\Core\Support\SiteSpec\SiteSpecPageContent;
use Capell\Core\Support\SiteSpec\SiteSpecPageDataFactory;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Writes a site out as a SiteSpec that can be applied to another copy of the site
 * with `capell:site-spec-import --site`. The result depends only on the site's
 * content, never on database IDs or the order rows were stored in, so the same
 * site exports identically on staging and production: the home page (a home page
 * type, or the home layout a spec's first page is given) comes first, then pages
 * by slug. Each page's body becomes one `content` section, sanitised as an
 * import would store it, so applying the export back to the same site changes
 * nothing. Name, title and body are what an apply compares and updates; the
 * page type is used for pages the spec creates.
 *
 * Package-owned parts (such as navigation), page properties and media are not
 * exported: the spec only carries remote media URLs, and Capell keeps a hash of
 * them, not the URL itself.
 */
final class ExportSiteSpecAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  bool  $public  Mark the spec public and acknowledged, so applying it updates pages that are live.
     *
     * @throws ValidationException when the site cannot be written as a valid SiteSpec, for example because it has more pages than a spec allows
     */
    public function handle(Site $site, bool $public = false): SiteSpecExportData
    {
        $language = $site->language;
        $pages = [];
        $omitted = [];

        $homePageIds = Page::query()->homePage()->where('site_id', $site->getKey())->pluck('id')->all();

        foreach ($site->pages()->with(['translations.translatable', 'blueprint', 'layout'])->get() as $page) {
            $translation = $page->translations->firstWhere('language_id', $language->getKey());
            $slug = $translation instanceof Translation ? ($translation->meta['slug'] ?? null) : null;

            if (! $translation instanceof Translation || ! is_string($slug) || preg_match('/' . CapellSiteSpecConstraints::SLUG_PATTERN . '/', $slug) !== 1) {
                $omitted[SiteSpecExportData::OMITTED_NO_SLUG][] = $page->name;

                continue;
            }

            $home = in_array($page->getKey(), $homePageIds, true) || $page->layout?->key === 'home';
            $reason = match (true) {
                ! $page->blueprint instanceof Blueprint => SiteSpecExportData::OMITTED_NO_PAGE_TYPE,
                ! is_string($translation->content) && $translation->content !== null => SiteSpecExportData::OMITTED_NOT_HTML,
                $this->isPlaceholder($site, $page, $translation, $home) => SiteSpecExportData::OMITTED_PLACEHOLDER,
                default => null,
            };

            if ($reason !== null) {
                $omitted[$reason][] = $page->name;

                continue;
            }

            $pages[] = ['home' => $home, 'spec' => $this->page($page, $page->blueprint, $translation, $slug)];
        }

        usort($pages, static fn (array $a, array $b): int => [$b['home'], $a['spec']['slug']] <=> [$a['home'], $b['spec']['slug']]);

        $payload = [
            'site' => $this->site($site),
            'theme' => $this->theme($site->theme),
            'language' => $this->language($language),
            'pages' => array_map(static fn (array $page, int $index): array => [...$page['spec'], 'order' => $index], $pages, array_keys($pages)),
            'initialVisibility' => $public ? 'public' : 'private',
            'acknowledgePublic' => $public,
        ];

        $validation = ValidateSiteSpecAction::run($payload, [], [], []);

        if (! $validation['valid'] || ! is_array($validation['normalized'])) {
            throw ValidationException::withMessages($validation['errors']);
        }

        ksort($omitted);
        $omitted = array_map(static function (array $names): array {
            sort($names);

            return $names;
        }, $omitted);

        return new SiteSpecExportData(CapellSiteSpecData::from($validation['normalized']), $omitted);
    }

    /** @return array<string, mixed> */
    private function site(Site $site): array
    {
        $meta = $site->meta ?? [];

        return [
            'name' => $site->name,
            'businessName' => is_string($meta['business_name'] ?? null) ? $meta['business_name'] : null,
            'organisationType' => is_string($meta['organization_type'] ?? null) ? $meta['organization_type'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function theme(?Theme $theme): array
    {
        if (! $theme instanceof Theme) {
            return ['key' => 'default'];
        }

        $meta = is_array($theme->meta) ? $theme->meta : [];
        $colors = is_array($meta['colors'] ?? null) ? $meta['colors'] : [];
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        // The contract carries hex colours only; others (such as rgb()) are left as they are on the target.
        $hex = static fn (mixed $value): ?string => is_string($value) && preg_match('/' . CapellSiteSpecConstraints::HEX_COLOUR_PATTERN . '/', $value) === 1 ? $value : null;

        return [
            'key' => $theme->key,
            'colors' => [
                'primary' => $hex($colors['primary'] ?? null),
                'secondary' => $hex($colors['secondary'] ?? null),
                'accent' => $hex($colors['accent'] ?? null),
            ],
            'fontFamily' => $string($meta['font_family'] ?? null),
            'linkColor' => $hex($meta['link_color'] ?? null),
            'linkColorActive' => $hex($meta['link_color_active'] ?? null),
            'container' => $string($meta['container'] ?? null),
            'customCss' => $string($theme->custom_css),
        ];
    }

    /** @return array<string, mixed> */
    private function language(Language $language): array
    {
        return [
            'code' => $language->code,
            'name' => $language->name,
            'locale' => $language->locale ?? $language->code,
            'flag' => $language->flag ?? $language->code,
            'default' => (bool) $language->default,
        ];
    }

    /** A private import's "Coming soon" home page, or a fresh install's untouched home page: neither is content to promote. */
    private function isPlaceholder(Site $site, Page $page, Translation $translation, bool $home): bool
    {
        return $home && ($translation->content === SiteSpecPageDataFactory::PLACEHOLDER_CONTENT || ResolveGuidedSetupStateAction::isPlaceholderHome($site, $page));
    }

    /** @return array<string, mixed> */
    private function page(Page $page, Blueprint $pageType, Translation $translation, string $slug): array
    {
        // The body as an import would store it, so applying the export back changes nothing.
        $content = SiteSpecPageContent::sanitisedBody((string) $translation->content);
        $title = filled($translation->title) ? (string) $translation->title : $page->name;

        return [
            'name' => $page->name,
            'slug' => $slug,
            'title' => $title,
            'pageType' => $pageType->key,
            'sections' => $content === '' ? [] : [['type' => 'content', 'content' => $content, 'order' => 0]],
        ];
    }
}
