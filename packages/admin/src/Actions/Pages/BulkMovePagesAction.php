<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $targetParentDenied = Gate::forUser($actor)->inspect('update', $newParent)->denied();

        // Wrap the whole batch in a single transaction so a mid-batch failure
        // rolls back every earlier move — no half-applied state on the public
        // frontend (which would silently break routing for affected pages).
        try {
            DB::transaction(function () use (
                $pages,
                $newParent,
                $actor,
                $addRedirects,
                $targetParentDenied,
                &$movedCount,
                &$skippedCount,
                &$redirectCount,
                &$skippedPages,
                &$currentPage
            ): void {
                foreach ($pages as $page) {
                    $currentPage = $page;
                    $reason = $this->skipReason($page, $newParent, $actor, $targetParentDenied);
                    if ($reason !== null) {
                        $skippedCount++;
                        $skippedPages[] = [
                            'id' => (int) $page->getKey(),
                            'name' => (string) $page->getAttribute('name'),
                            'reason' => $reason,
                        ];

                        continue;
                    }

                    $preMoveUrls = $addRedirects ? $this->captureUrls($page) : [];

                    $page->parent_id = $newParent->getKey();
                    $page->save();
                    SetupPageUrlsAction::run($page);
                    $movedCount++;

                    if ($addRedirects) {
                        $redirectCount += $this->createRedirects($preMoveUrls);
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

    private function skipReason(Page $page, Page $newParent, User $actor, bool $targetParentDenied): ?string
    {
        if ($targetParentDenied) {
            return 'target_parent_denied';
        }

        if ($this->wouldCrossSites($page, $newParent)) {
            return 'cross_sites';
        }

        if ($this->wouldCreateCycle($page, $newParent)) {
            return 'cycle';
        }

        if (Gate::forUser($actor)->inspect('update', $page)->denied()) {
            return 'unauthorized';
        }

        return null;
    }

    private function wouldCreateCycle(Page $page, Page $newParent): bool
    {
        if ($page->is($newParent)) {
            return true;
        }

        $pageKey = $page->getKey();
        $current = $newParent;

        while ($current->parent_id !== null) {
            if ($current->parent_id === $pageKey) {
                return true;
            }

            $current = Page::query()->find($current->parent_id);

            if ($current === null) {
                return false;
            }
        }

        return false;
    }

    private function wouldCrossSites(Page $page, Page $newParent): bool
    {
        return $page->site_id !== $newParent->site_id;
    }

    /**
     * Snapshot every canonical URL for the page and its descendants, keyed
     * for later redirect creation after the move rewrites them. Canonical
     * URLs are those that are not of type Redirect (auto-generated aliases
     * have a NULL type; only manually added redirects are tagged Redirect).
     *
     * @return array<int, array{pageable: Pageable&Page, language: Language, url: string, site_id: int}>
     */
    private function captureUrls(Page $page): array
    {
        /** @var Collection<int, Page> $pages */
        $pages = new Collection([$page]);
        /** @var Collection<int, Page> $descendants */
        $descendants = $page->descendants()->get();
        $pages = $pages->merge($descendants);

        $snapshots = [];

        foreach ($pages as $target) {
            $target->load('pageUrls.language');

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
    private function createRedirects(array $snapshots): int
    {
        $created = 0;

        foreach ($snapshots as $snapshot) {
            /** @var Pageable&Page $pageable */
            $pageable = $snapshot['pageable'];
            $oldUrl = $snapshot['url'];
            $language = $snapshot['language'];
            $siteId = $snapshot['site_id'];

            $pageable->load('pageUrls');

            $currentAlias = $pageable->pageUrls
                ->first(fn (PageUrl $pageUrl): bool => $pageUrl->type !== UrlTypeEnum::Redirect
                    && $pageUrl->language_id === $language->getKey());

            if ($currentAlias !== null && $currentAlias->url === $oldUrl) {
                continue;
            }

            if ($this->redirectUrlExists($siteId, $language->getKey(), $oldUrl)) {
                continue;
            }

            resolve(RedirectUrlRecorder::class)->record($pageable, $language, $oldUrl);
            $created++;
        }

        return $created;
    }

    private function redirectUrlExists(int $siteId, int $languageId, string $url): bool
    {
        return PageUrl::query()
            ->where('site_id', $siteId)
            ->where('language_id', $languageId)
            ->where('url', $url)
            ->exists();
    }
}
