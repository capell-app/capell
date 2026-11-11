<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanItemData;
use Capell\Core\Enums\SiteSpecPlanAction;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\EventSourcing\Support\PageVersionContext;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Core\Support\SiteSpec\PrivateSiteSpecSourceStore;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecHasher;
use Capell\Core\Support\SiteSpec\SiteSpecMediaDownload;
use Capell\Core\Support\SiteSpec\SiteSpecPageContent;
use Capell\Core\Support\SiteSpec\SiteSpecPageDataFactory;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Applies a SiteSpec to a site that already exists: pages are matched by slug and
 * created or updated, theme settings are merged, and package-owned parts run
 * their appliers again. Publication is never changed for a page that exists, so
 * applying a private spec cannot unpublish a live page. The spec's hash is
 * recorded on the site as the last spec applied.
 */
final class ApplySiteSpecToSiteAction
{
    use AsFake;
    use AsObject;

    public function handle(CapellSiteSpecData $spec, Site $site): SiteSpecPlanData
    {
        throw_if($spec->initialVisibility === 'public' && ! $spec->acknowledgePublic, RuntimeException::class, 'Public site specs require explicit acknowledgement.');
        AssertSiteSpecCanBeAppliedAction::run($spec);

        $language = Language::query()->where('code', $spec->language->code)->first();

        throw_unless(
            $language instanceof Language && $site->languages->contains('id', $language->getKey()),
            RuntimeException::class,
            sprintf('The site does not have the language [%s] this site spec is written in.', $spec->language->code),
        );

        $hash = SiteSpecHasher::hash($spec);

        if ($spec->initialVisibility === 'private') {
            resolve(PrivateSiteSpecSourceStore::class)->put($hash, $spec);
        }

        $downloads = FetchSiteSpecMediaAction::run($spec->media);

        try {
            // Versions created by an apply are labelled as imports in page history.
            return resolve(PageVersionContext::class)->run(
                PageVersionSource::Import,
                null,
                fn (): SiteSpecPlanData => DB::transaction(fn (): SiteSpecPlanData => $this->apply($spec, $site, $language, $hash, $downloads)),
            );
        } finally {
            foreach ($downloads as $download) {
                if (is_file($download->path)) {
                    unlink($download->path);
                }
            }
        }
    }

    /** @param list<SiteSpecMediaDownload> $downloads */
    private function apply(CapellSiteSpecData $spec, Site $site, Language $language, string $hash, array $downloads): SiteSpecPlanData
    {
        $planner = PlanSiteSpecAction::make();
        $plan = $planner->handle($spec, $site);
        $existing = $planner->existingPagesBySlug($spec, $site);
        $actions = [];

        foreach ($plan->items as $item) {
            $actions[$item->kind . ':' . $item->key] = $item;
        }

        $creator = resolve(PageCreator::class);
        $pagesBySlug = [];

        foreach ($spec->pages as $pageSpec) {
            $item = $actions['page:' . $pageSpec->slug];
            $key = ltrim($pageSpec->slug, '/');

            if ($item->action === SiteSpecPlanAction::Create) {
                $pagesBySlug[$pageSpec->slug] = $this->createPage($creator, $pageSpec, $spec, $site, $language);

                continue;
            }

            $match = $existing[$key];
            $pagesBySlug[$pageSpec->slug] = $match['page'];

            if ($item->action === SiteSpecPlanAction::Update) {
                $this->updatePage($pageSpec, $match['page'], $match['translation'], $item);
            }
        }

        $theme = $site->theme;

        if (($actions['theme:' . ($theme->key ?? '')] ?? null)?->action === SiteSpecPlanAction::Update && $theme !== null) {
            MergeSiteSpecThemeSettingsAction::run($theme, $spec);
        }

        $applied = [];

        foreach ($plan->appliers as $key) {
            if (! in_array($key, $applied, true)) {
                resolve(SiteSpecApplierRegistry::class)->apply($key, $spec, $site, $pagesBySlug);
                $applied[] = $key;
            }
        }

        AttachSiteSpecMediaAction::run($site, $pagesBySlug, $downloads);

        $site->forceFill(['meta' => array_merge($site->meta ?? [], ['site_spec_hash' => $hash])])->save();
        RecordImportedPageVersionsAction::run($site);

        return $plan;
    }

    private function createPage(PageCreator $creator, CapellSiteSpecPageData $pageSpec, CapellSiteSpecData $spec, Site $site, Language $language): Page
    {
        $page = $creator->createPage(
            SiteSpecPageDataFactory::make($pageSpec, $language, false, $spec->initialVisibility === 'public'),
            $site,
            collect([$language]),
        );

        throw_unless($page instanceof Page, RuntimeException::class, sprintf('Site spec page [%s] did not resolve to a Capell page model.', $pageSpec->slug));
        SetupPageUrlsAction::run($page);

        return $page;
    }

    private function updatePage(CapellSiteSpecPageData $pageSpec, Page $page, Translation $translation, SiteSpecPlanItemData $item): void
    {
        if (in_array('name', $item->changes, true)) {
            $page->update(['name' => $pageSpec->name]);
        }

        $translationChanges = array_filter([
            'title' => in_array('title', $item->changes, true) ? $pageSpec->title : null,
            'content' => in_array('content', $item->changes, true) ? SiteSpecPageContent::html($pageSpec) : null,
        ], static fn (?string $value): bool => $value !== null);

        if ($translationChanges !== []) {
            $translation->update($translationChanges);
        }
    }
}
