<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Events\SiteSpecPrivacyRepairing;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\EventSourcing\Support\PageVersionContext;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\SiteSpec\PrivateSiteSpecSourceStore;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecHasher;
use Capell\Core\Support\SiteSpec\SiteSpecMediaDownload;
use Capell\Core\Support\SiteSpec\SiteSpecPageDataFactory;
use Capell\Core\Support\SiteSpec\SiteSpecPageSlug;
use Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class BuildCapellSiteFromSpecAction
{
    use AsFake;
    use AsObject;

    /** @param list<string> $keepPublished */
    public function handle(CapellSiteSpecData $spec, bool $repairPrivate = false, array $keepPublished = [], bool $auditPrivate = false): Site
    {
        // Versions created by an import are labelled as such in page history.
        return resolve(PageVersionContext::class)->run(
            PageVersionSource::Import,
            null,
            function () use ($spec, $repairPrivate, $keepPublished, $auditPrivate): Site {
                $site = $this->build($spec, $repairPrivate, $keepPublished, $auditPrivate);

                if (! $auditPrivate) {
                    RecordImportedPageVersionsAction::run($site);
                }

                return $site;
            },
        );
    }

    /**
     * @param  list<string>  $keepPublished
     */
    private function build(CapellSiteSpecData $spec, bool $repairPrivate, array $keepPublished, bool $auditPrivate): Site
    {
        throw_if(($repairPrivate || $auditPrivate) && $spec->initialVisibility !== 'private', RuntimeException::class, 'Privacy audit and repair require the original private spec.');
        $hash = SiteSpecHasher::hash($spec);
        $existing = Site::query()->where('meta->site_spec_hash', $hash)->first();

        if ($existing instanceof Site) {
            if ($auditPrivate) {
                return $existing;
            }

            if ($spec->initialVisibility === 'private' && ($existing->meta['site_spec_privacy_version'] ?? null) !== 2) {
                throw_unless($repairPrivate, RuntimeException::class, (string) __('capell-core::message.site_spec_private_audit_required', ['id' => $existing->id]));
                resolve(PrivateSiteSpecSourceStore::class)->put($hash, $spec);

                return $this->repairPrivateImport($existing, $spec, $keepPublished);
            }

            return $existing;
        }

        throw_if($repairPrivate || $auditPrivate, RuntimeException::class, 'No existing import matches this original spec. No repair was applied.');

        throw_if($spec->initialVisibility === 'public' && ! $spec->acknowledgePublic, RuntimeException::class, 'Public site specs require explicit acknowledgement.');
        AssertSiteSpecCanBeAppliedAction::run($spec);

        if ($spec->initialVisibility === 'private') {
            resolve(PrivateSiteSpecSourceStore::class)->put($hash, $spec);
        }

        $downloads = FetchSiteSpecMediaAction::run($spec->media);

        try {
            return DB::transaction(function () use ($spec, $hash, $downloads): Site {
                $language = $this->resolveLanguage($spec);
                $theme = $this->buildTheme($spec);

                resolve(ThemeInstallDefaultsRegistry::class)->install($spec->theme->key);

                $site = CreateSiteAction::run($spec->site->name, null, $language, collect([$language]), $theme);
                $site->meta = array_merge($site->meta ?? [], array_filter([
                    'business_name' => $spec->site->businessName,
                    'organization_type' => $spec->site->organisationType,
                ]), ['site_spec_hash' => $hash], $spec->initialVisibility === 'private' ? ['site_spec_privacy_version' => 2] : []);
                $site->save();

                $creator = resolve(PageCreator::class);
                $pagesBySlug = [];

                foreach ($this->orderedPages($spec) as $index => $page) {
                    $createdPage = $creator->createPage(
                        SiteSpecPageDataFactory::make($page, $language, $index === 0, $spec->initialVisibility === 'public'),
                        $site,
                        collect([$language]),
                    );
                    throw_unless($createdPage instanceof Page, RuntimeException::class, sprintf(
                        'Site spec page [%s] did not resolve to a Capell page model.',
                        $page->slug,
                    ));
                    SetupPageUrlsAction::run($createdPage);
                    $pagesBySlug[$page->slug] = $createdPage;
                }

                $appliedSiteSpecKeys = [];

                if ($spec->navigations !== []) {
                    resolve(SiteSpecApplierRegistry::class)->apply('navigation', $spec, $site, $pagesBySlug);
                    $appliedSiteSpecKeys[] = 'navigation';
                }

                foreach (array_keys($spec->packageData) as $key) {
                    if (in_array($key, $appliedSiteSpecKeys, true)) {
                        continue;
                    }

                    resolve(SiteSpecApplierRegistry::class)->apply($key, $spec, $site, $pagesBySlug);
                }

                AttachSiteSpecMediaAction::run($site, $pagesBySlug, $downloads);

                return $site->refresh();
            });
        } finally {
            $this->deleteDownloads($downloads);
        }
    }

    /** @param list<SiteSpecMediaDownload> $downloads */
    private function deleteDownloads(array $downloads): void
    {
        foreach ($downloads as $download) {
            if (is_file($download->path)) {
                unlink($download->path);
            }
        }
    }

    private function resolveLanguage(CapellSiteSpecData $spec): Language
    {
        $language = Language::query()->where('code', $spec->language->code)->first()
            ?? CreateDefaultLanguagesAction::run([$spec->language->code])->first()
            ?? throw new RuntimeException(sprintf('Language [%s] could not be resolved.', $spec->language->code));

        $language->forceFill(Arr::only($spec->language->toArray(), ['name', 'locale', 'flag', 'default']))->save();

        return $language->refresh();
    }

    private function buildTheme(CapellSiteSpecData $spec): Theme
    {
        return MergeSiteSpecThemeSettingsAction::run(
            CreateThemeAction::run($spec->theme->key, ucfirst($spec->theme->key)),
            $spec,
        );
    }

    /** @return list<CapellSiteSpecPageData> */
    private function orderedPages(CapellSiteSpecData $spec): array
    {
        return array_values(collect($spec->pages)->sortBy('order')->all());
    }

    /** @param list<string> $keepPublished */
    private function repairPrivateImport(Site $site, CapellSiteSpecData $spec, array $keepPublished): Site
    {
        $slugs = array_map(static fn (CapellSiteSpecPageData $page): string => $page->slug, $spec->pages);
        throw_if(array_diff($keepPublished, $slugs) !== [], RuntimeException::class, 'Every kept page must belong to the original private spec.');

        DB::transaction(function () use ($site, $spec, $keepPublished): void {
            foreach ($this->orderedPages($spec) as $index => $pageSpec) {
                if (in_array($pageSpec->slug, $keepPublished, true)) {
                    continue;
                }

                $slug = SiteSpecPageSlug::for($pageSpec);
                $pages = $site->pages()->whereHas('translations', fn (Builder $query): Builder => $query
                    ->where('language_id', $site->language_id)->where('meta->slug', $slug))->lockForUpdate()->get();
                throw_unless($pages->count() === 1, RuntimeException::class, 'Cannot identify the original imported page: ' . $pageSpec->slug);
                $page = $pages->firstOrFail();

                if ($index !== 0) {
                    // Dates assigned after import, including deliberate publication
                    // and schedules, remain authoritative. Nulls require the audit.
                    if ($page->visible_from === null) {
                        $page->update(['visible_from' => PublishSentinel::draftValue()]);
                    }

                    continue;
                }

                $translation = $page->translations()->where('language_id', $site->language_id)->lockForUpdate()->firstOrFail();
                throw_unless(
                    $translation->title === 'Coming soon'
                    && $translation->content === '<h1>Coming soon</h1><p>This site is being prepared.</p>',
                    RuntimeException::class,
                    'The homepage was edited after import; review it and explicitly keep it before repair.',
                );
                $safe = SiteSpecPageDataFactory::make($pageSpec, $site->language, true, false);
                $page->update(['meta' => $safe['meta']]);
                $translation->update(['meta' => array_merge($safe['translations'][$site->language->code]['meta'], [
                    'slug' => $translation->slug, 'summary' => 'This site is being prepared.',
                ])]);
            }
        });

        // Commit publication changes before purging, so readers cannot refill
        // the public cache from the pre-repair database state. Failed purges
        // leave safe database state and no completion marker, allowing retries.
        $site->getConnection()->afterCommit(function () use ($site): void {
            event(new SiteSpecPrivacyRepairing($site));
            $site->refresh()->update(['meta' => array_merge($site->meta ?? [], ['site_spec_privacy_version' => 2])]);
        });

        return $site->refresh();
    }
}
