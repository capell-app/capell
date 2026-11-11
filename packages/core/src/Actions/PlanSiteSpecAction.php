<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanItemData;
use Capell\Core\Enums\SiteSpecPlanAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\SiteSpecPageContent;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Works out what applying a SiteSpec would do to a site, and writes nothing.
 * Pages are matched by slug. With no site, every page would be created. A page
 * that is live or scheduled is left alone by a private spec, so applying one
 * cannot publish private copy.
 */
final class PlanSiteSpecAction
{
    use AsFake;
    use AsObject;

    public function handle(CapellSiteSpecData $spec, ?Site $site = null): SiteSpecPlanData
    {
        $existing = $site instanceof Site ? $this->existingPagesBySlug($spec, $site) : [];
        $items = [];

        foreach ($spec->pages as $pageSpec) {
            $match = $existing[ltrim($pageSpec->slug, '/')] ?? null;
            $items[] = $this->pageItem($pageSpec, $match['page'] ?? null, $match['translation'] ?? null, $spec->initialVisibility === 'private');
        }

        $themeItem = $site instanceof Site ? $this->themeItem($spec, $site) : null;

        if ($themeItem instanceof SiteSpecPlanItemData) {
            $items[] = $themeItem;
        }

        return new SiteSpecPlanData($items, $this->applierKeys($spec));
    }

    /**
     * The site's pages that carry a slug in the spec's language, keyed by that slug.
     *
     * @return array<string, array{page: Page, translation: Translation}>
     */
    public function existingPagesBySlug(CapellSiteSpecData $spec, Site $site): array
    {
        $language = Language::query()->where('code', $spec->language->code)->first();

        if (! $language instanceof Language) {
            return [];
        }

        $pages = [];

        foreach ($site->pages()->with('translations')->get() as $page) {
            $translation = $page->translations->firstWhere('language_id', $language->getKey());
            $slug = $translation instanceof Translation ? ($translation->meta['slug'] ?? null) : null;

            if ($translation instanceof Translation && is_string($slug) && $slug !== '') {
                $pages[$slug] = ['page' => $page, 'translation' => $translation];
            }
        }

        return $pages;
    }

    private function pageItem(CapellSiteSpecPageData $pageSpec, ?Page $page, ?Translation $translation, bool $privateSpec): SiteSpecPlanItemData
    {
        if (! $page instanceof Page || ! $translation instanceof Translation) {
            return new SiteSpecPlanItemData('page', $pageSpec->slug, SiteSpecPlanAction::Create);
        }

        $changes = array_keys(array_filter([
            'name' => $page->name !== $pageSpec->name,
            'title' => $translation->title !== $pageSpec->title,
            'content' => (string) $translation->content !== SiteSpecPageContent::html($pageSpec),
        ]));

        if ($changes === []) {
            return new SiteSpecPlanItemData('page', $pageSpec->slug, SiteSpecPlanAction::Skip);
        }

        // A private spec must not push its copy onto a page that is live or scheduled.
        if ($privateSpec && ! PublishSentinel::isDraftValue($page->visible_from)) {
            return new SiteSpecPlanItemData('page', $pageSpec->slug, SiteSpecPlanAction::Skip, $changes, SiteSpecPlanItemData::NOTE_LIVE_PAGE_HELD);
        }

        return new SiteSpecPlanItemData('page', $pageSpec->slug, SiteSpecPlanAction::Update, $changes);
    }

    private function themeItem(CapellSiteSpecData $spec, Site $site): ?SiteSpecPlanItemData
    {
        $theme = $site->theme;

        if ($theme === null || $theme->key !== $spec->theme->key) {
            return null;
        }

        return new SiteSpecPlanItemData(
            'theme',
            $theme->key,
            MergeSiteSpecThemeSettingsAction::make()->wouldChange($theme, $spec) ? SiteSpecPlanAction::Update : SiteSpecPlanAction::Skip,
        );
    }

    /** @return list<string> */
    private function applierKeys(CapellSiteSpecData $spec): array
    {
        $keys = $spec->navigations === [] ? [] : ['navigation'];

        foreach (array_keys($spec->packageData) as $key) {
            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
