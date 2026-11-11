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
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecHasher;
use Capell\Core\Support\SiteSpec\SiteSpecMediaDownload;
use Capell\Core\Support\SiteSpec\SiteSpecPageContent;
use Capell\Core\Support\SiteSpec\SiteSpecPageDataFactory;
use Capell\Core\Support\SiteSpec\SiteSpecPageSlug;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Applies a SiteSpec to a site that already exists: pages are matched by slug and
 * created or updated, theme settings are merged, and package-owned parts run
 * their appliers once per distinct spec. Publication is never changed for a page
 * that exists, and a private spec leaves live pages, their media and package parts
 * alone, so applying one cannot publish private copy. The spec's hash is recorded
 * on the site as the last spec applied, beside the hash of the original import.
 */
final class ApplySiteSpecToSiteAction
{
    use AsFake;
    use AsObject;

    public function handle(CapellSiteSpecData $spec, Site $site): SiteSpecPlanData
    {
        // Planning runs the same checks as applying, before anything is written.
        PlanSiteSpecAction::run($spec, $site);

        $language = Language::query()->where('code', $spec->language->code)->firstOrFail();
        $hash = SiteSpecHasher::hash($spec);
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
        $writable = [];

        foreach ($spec->pages as $pageSpec) {
            $item = $actions['page:' . $pageSpec->slug];

            if ($item->action === SiteSpecPlanAction::Create) {
                $pagesBySlug[$pageSpec->slug] = $this->createPage($creator, $pageSpec, $spec, $site, $language);
                $writable[] = $pageSpec->slug;

                continue;
            }

            $match = $existing[SiteSpecPageSlug::for($pageSpec)];
            $pagesBySlug[$pageSpec->slug] = $match['page'];

            if ($item->action === SiteSpecPlanAction::Update) {
                $this->updatePage($pageSpec, $match['page'], $match['translation'], $item);
                $writable[] = $pageSpec->slug;
            }
        }

        $theme = $site->theme;

        if (($actions['theme:' . ($theme->key ?? '')] ?? null)?->action === SiteSpecPlanAction::Update && $theme !== null) {
            MergeSiteSpecThemeSettingsAction::run($theme, $spec);
        }

        // Package-owned parts and media run once per distinct spec, and not at all
        // while a private spec is being held back from live pages.
        $runPackageParts = ! $plan->packagePartsHeld && ($site->meta['site_spec_applied_hash'] ?? null) !== $hash;

        if ($runPackageParts) {
            foreach ($plan->appliers as $key) {
                resolve(SiteSpecApplierRegistry::class)->apply($key, $spec, $site, $pagesBySlug);
            }
        }

        // The original import hash stays as it was: it is how the import is found again and repaired.
        $site->forceFill(['meta' => array_merge($site->meta ?? [], ['site_spec_applied_hash' => $hash])])->save();
        RecordImportedPageVersionsAction::run($site);

        // Last, so a failure above leaves no stored files behind to be orphaned by the rollback.
        if ($runPackageParts) {
            AttachSiteSpecMediaAction::run($site, $pagesBySlug, $this->downloadsToAttach($spec, $site, $pagesBySlug, $writable, $downloads));
        }

        return $plan;
    }

    /**
     * Only media that is not already attached, and, for a private spec, only media for
     * pages the spec just created or updated as drafts: its images must not reach a
     * live page, and its site logo must not reach a live site.
     *
     * @param  array<string, Page>  $pagesBySlug
     * @param  list<string>  $writable
     * @param  list<SiteSpecMediaDownload>  $downloads
     * @return list<SiteSpecMediaDownload>
     */
    private function downloadsToAttach(CapellSiteSpecData $spec, Site $site, array $pagesBySlug, array $writable, array $downloads): array
    {
        $private = $spec->initialVisibility === 'private';

        return array_values(array_filter($downloads, function (SiteSpecMediaDownload $download) use ($private, $site, $pagesBySlug, $writable): bool {
            if ($private && ($download->pageSlug === null || ! in_array($download->pageSlug, $writable, true))) {
                return false;
            }

            $owner = $download->pageSlug === null ? $site : ($pagesBySlug[$download->pageSlug] ?? null);

            return $owner !== null && $owner->media()->get()->doesntContain(
                static fn ($media): bool => $media->getCustomProperty('capell.site_spec.source_hash') === $download->sourceHash,
            );
        }));
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
        ApplySiteSpecPagePropertiesAction::run($page, $pageSpec->slug, $pageSpec->properties, (int) $language->getKey());

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
