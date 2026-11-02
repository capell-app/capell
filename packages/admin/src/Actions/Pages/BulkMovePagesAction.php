<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\BatchRedirectUrlRecorder;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Data\Redirects\RedirectUrlRecordData;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Support\Url\PageUrlRewriteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Moves a collection of Page records under a new parent.
 * Pages for which the actor lacks the `update` permission, or where the
 * proposed parent would create a cycle (the new parent is the page itself
 * or one of its descendants), are skipped; the returned count reflects
 * only pages that were actually moved.
 *
 * When $addRedirects is true, for every page that is actually moved (and
 * each of its descendants, since the parent move rewrites their URLs too),
 * the pre-move canonical URL is captured per language and re-created as a
 * `redirect` PageUrl so the old paths 301 to the new location. Duplicate
 * redirects (URL already exists for the site/language) and no-op moves
 * (old URL equals new URL) are silently skipped.
 */
class BulkMovePagesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Page&Pageable>  $pages
     * @return array{
     *     moved: int,
     *     skipped: int,
     *     redirects: int,
     *     skipped_pages: list<array{id: int, name: string, reason: string}>,
     *     failed_at: ?array{id: int, name: string, reason: string}
     * }
     */
    public function handle(Collection $pages, Page $newParent, User $actor, bool $addRedirects = false): array
    {
        $movedCount = 0;
        $skippedCount = 0;
        $redirectCount = 0;
        $skippedPages = [];
        $failedAt = null;
        $currentPage = null;

        // Wrap the whole batch in a single transaction so a mid-batch failure
        // rolls back every earlier move — no half-applied state on the public
        // frontend (which would silently break routing for affected pages).
        try {
            DB::transaction(function () use (
                $pages,
                $newParent,
                $actor,
                $addRedirects,
                &$movedCount,
                &$skippedCount,
                &$redirectCount,
                &$skippedPages,
                &$currentPage
            ): void {
                foreach ($pages as $page) {
                    $currentPage = $page;
                    $reason = ResolvePageMoveSkipReasonAction::run($page, $newParent, $actor);
                    if ($reason !== null) {
                        $skippedCount++;
                        $skippedPages[] = [
                            'id' => (int) $page->getKey(),
                            'name' => (string) $page->getAttribute('name'),
                            'reason' => $reason,
                        ];

                        continue;
                    }

                    $preMoveUrls = $addRedirects ? $this->captureUrls($page, $actor) : [];

                    $page->parent_id = $newParent->getKey();
                    resolve(PageUrlRewriteContext::class)->withoutAutomaticRedirects(function () use ($page): void {
                        $page->save();
                    });
                    SetupPageUrlsAction::run($page, automaticRedirectsAllowed: false);
                    $movedCount++;

                    if ($addRedirects) {
                        $redirectCount += $this->createRedirects($preMoveUrls, $actor);
                    }
                }
            });
        } catch (Throwable $throwable) {
            $failedAt = [
                'id' => $currentPage instanceof Page ? (int) $currentPage->getKey() : 0,
                'name' => $currentPage instanceof Page ? (string) $currentPage->getAttribute('name') : '',
                'reason' => $throwable->getMessage(),
            ];
            $movedCount = 0; // transaction rolled back; nothing actually moved
            $redirectCount = 0;
        }

        return [
            'moved' => $movedCount,
            'skipped' => $skippedCount,
            'redirects' => $redirectCount,
            'skipped_pages' => $skippedPages,
            'failed_at' => $failedAt,
        ];
    }

    /**
     * Snapshot every canonical URL for the page and its descendants, keyed
     * for later redirect creation after the move rewrites them. Canonical
     * URLs are those that are not of type Redirect (auto-generated aliases
     * have a NULL type; only manually added redirects are tagged Redirect).
     *
     * @return array<int, array{pageable: Pageable&Page, language: Language, url: string, site_id: int}>
     */
    private function captureUrls(Page $page, User $actor): array
    {
        /** @var Collection<int, Page> $pages */
        $pages = new Collection([$page]);
        /** @var Collection<int, Page> $descendants */
        $descendants = SiteAccess::forActor($actor)->scope($page->descendants()->getQuery())->get();
        $pages = $pages->merge($descendants);

        $pages->load('pageUrls.language');

        $snapshots = [];

        foreach ($pages as $target) {
            foreach ($target->pageUrls as $pageUrl) {
                if ($pageUrl->type === UrlTypeEnum::Redirect) {
                    continue;
                }

                $snapshots[] = [
                    'pageable' => $target,
                    'language' => $pageUrl->language,
                    'url' => $pageUrl->url,
                    'site_id' => $pageUrl->site_id,
                ];
            }
        }

        return $snapshots;
    }

    /**
     * @param  array<int, array{pageable: Pageable&Page, language: Language, url: string, site_id: int}>  $snapshots
     */
    private function createRedirects(array $snapshots, User $actor): int
    {
        if ($snapshots === []) {
            return 0;
        }

        // Reload every moved page's URLs together: the move has just rewritten them.
        /** @var Collection<int, Page> $pageables */
        $pageables = new Collection(array_values(collect($snapshots)
            ->map(fn (array $snapshot): Page => $snapshot['pageable'])
            ->unique(fn (Page $page): int => (int) $page->getKey())
            ->all()));
        $pageables->load('pageUrls');

        $records = [];

        foreach ($snapshots as $snapshot) {
            /** @var Pageable&Page $pageable */
            $pageable = $snapshot['pageable'];
            $oldUrl = $snapshot['url'];
            $language = $snapshot['language'];

            $currentAlias = $pageable->pageUrls
                ->first(fn (PageUrl $pageUrl): bool => $pageUrl->type !== UrlTypeEnum::Redirect
                    && $pageUrl->language_id === $language->getKey());

            if ($currentAlias !== null && $currentAlias->url === $oldUrl) {
                continue;
            }

            $records[] = ['site_id' => $snapshot['site_id'], 'record' => new RedirectUrlRecordData($pageable, $language, $oldUrl, $currentAlias?->url)];
        }

        $existing = $this->existingRedirectUrls($records, $actor);
        $toRecord = [];

        foreach ($records as ['site_id' => $siteId, 'record' => $record]) {
            $key = $siteId . ':' . $record->language->getKey() . ':' . $record->url;

            if (isset($existing[$key])) {
                continue;
            }

            $existing[$key] = true;
            $toRecord[] = $record;
        }

        $recorder = resolve(RedirectUrlRecorder::class);

        if ($recorder instanceof BatchRedirectUrlRecorder) {
            $recorder->recordMany($toRecord);
        } else {
            foreach ($toRecord as $record) {
                $recorder->record($record->pageable, $record->language, $record->url);
            }
        }

        return count($toRecord);
    }

    /**
     * Finds, with one lookup per site, language and chunk, which old URLs the actor can already see in use.
     *
     * @param  list<array{site_id: int, record: RedirectUrlRecordData<Page>}>  $records
     * @return array<string, true>
     */
    private function existingRedirectUrls(array $records, User $actor): array
    {
        $groups = [];

        foreach ($records as ['site_id' => $siteId, 'record' => $record]) {
            $groups[$siteId . ':' . $record->language->getKey()][$record->url] = true;
        }

        $existing = [];

        foreach ($groups as $group => $urls) {
            [$siteId, $languageId] = array_map(intval(...), explode(':', (string) $group));

            foreach (array_chunk(array_keys($urls), 500) as $chunk) {
                foreach ($this->redirectUrlsQuery($siteId, $languageId, $actor)->whereIn('url', $chunk)->pluck('url') as $url) {
                    $existing[$group . ':' . $url] = true;
                }
            }
        }

        return $existing;
    }

    /** @return Builder<PageUrl> */
    private function redirectUrlsQuery(int $siteId, int $languageId, User $actor): Builder
    {
        return SiteAccess::forActor($actor)->query(PageUrl::class)
            ->where('site_id', $siteId)
            ->where('language_id', $languageId);
    }
}
