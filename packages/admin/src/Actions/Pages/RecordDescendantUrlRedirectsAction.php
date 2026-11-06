<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\DescendantUrlRedirectRequestData;
use Capell\Admin\Data\Pages\PageUrlRedirectResultData;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\BatchRedirectUrlRecorder;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Data\Redirects\RedirectUrlRecordData;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RecordDescendantUrlRedirectsAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly RedirectUrlRecorder $redirects,
    ) {}

    /**
     * @template TPageModel of Model
     *
     * @param  DescendantUrlRedirectRequestData<TPageModel>  $request
     */
    public function handle(DescendantUrlRedirectRequestData $request): PageUrlRedirectResultData
    {
        $acceptedUrls = $this->acceptSubmittedUrls($request);

        if ($acceptedUrls === []) {
            return new PageUrlRedirectResultData(
                acceptedCount: 0,
                recordedCount: 0,
            );
        }

        // Only pages that are still descendants of the edited page may
        // receive redirects; submitted ids cannot reach arbitrary pages.
        // Query by key: fresh tree bounds, not the instance's cached ones.
        // Only the accepted ids are hydrated, never the whole subtree.
        $descendants = $request->page->newQuery()
            ->whereDescendantOf($request->page->getKey())
            ->whereKey(array_keys($acceptedUrls))
            ->get()
            ->filter(fn (Model $descendant): bool => $descendant instanceof Pageable)
            ->keyBy(fn (Model $descendant): int => (int) $descendant->getKey());

        $languageIds = [];
        foreach ($acceptedUrls as $urls) {
            foreach (array_keys($urls) as $languageId) {
                $languageIds[$languageId] = true;
            }
        }

        $languages = Language::query()
            ->whereIn('id', array_keys($languageIds))
            ->get()
            ->keyBy(fn (Language $language): int => (int) $language->getKey());

        $currentUrls = $this->currentUrls($request->page->getMorphClass(), array_keys($descendants->all()), array_keys($languageIds));
        $acceptedCount = 0;
        /** @var list<RedirectUrlRecordData> $records */
        $records = [];

        foreach ($acceptedUrls as $pageId => $urls) {
            $descendant = $descendants->get($pageId);

            if (! $descendant instanceof Pageable) {
                continue;
            }

            foreach ($urls as $languageId => $oldUrl) {
                $acceptedCount++;

                $language = $languages->get($languageId);

                if (! $language instanceof Language) {
                    continue;
                }

                if (($currentUrls[$pageId][$languageId] ?? null) === $oldUrl) {
                    continue;
                }

                $records[] = new RedirectUrlRecordData($descendant, $language, $oldUrl);
            }
        }

        if ($this->redirects instanceof BatchRedirectUrlRecorder) {
            $this->redirects->recordMany($records);
        } else {
            foreach ($records as $record) {
                /** @var Pageable<Model> $pageable */
                $pageable = $record->pageable;
                $this->redirects->record($pageable, $record->language, $record->url);
            }
        }

        return new PageUrlRedirectResultData(
            acceptedCount: $acceptedCount,
            recordedCount: count($records),
        );
    }

    /**
     * Keep only submitted URLs that exactly match the expected snapshot,
     * mirroring the anti-tamper check in RecordPageUrlRedirectsAction.
     *
     * @template TPageModel of Model
     *
     * @param  DescendantUrlRedirectRequestData<TPageModel>  $request
     * @return array<int, array<int, string>>
     */
    private function acceptSubmittedUrls(DescendantUrlRedirectRequestData $request): array
    {
        $acceptedUrls = [];

        foreach ($request->submittedUrls as $pageId => $urls) {
            if (! is_array($urls)) {
                continue;
            }

            $expectedForPage = $request->expectedUrls[$pageId] ?? null;

            if (! is_array($expectedForPage)) {
                continue;
            }

            foreach ($urls as $languageId => $url) {
                $expectedUrl = $expectedForPage[$languageId] ?? null;
                if (! is_string($expectedUrl)) {
                    continue;
                }

                if (! is_string($url)) {
                    continue;
                }

                if (! hash_equals($expectedUrl, $url)) {
                    continue;
                }

                $acceptedUrls[(int) $pageId][(int) $languageId] = $url;
            }
        }

        return $acceptedUrls;
    }

    /**
     * The current (non-redirect) address of each page and language, read in
     * one query instead of once per descendant and language.
     *
     * @param  list<int>  $pageIds
     * @param  list<int>  $languageIds
     * @return array<int, array<int, string>>
     */
    private function currentUrls(string $morphClass, array $pageIds, array $languageIds): array
    {
        if ($pageIds === [] || $languageIds === []) {
            return [];
        }

        // The ids are descendants of the page the caller is authorised to edit.
        $rows = PageUrl::query()
            ->where('pageable_type', $morphClass)
            ->whereIn('pageable_id', $pageIds)
            ->whereIn('language_id', $languageIds)
            ->where(fn (Builder $query): Builder => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->orderBy('id')
            ->toBase()
            ->get(['pageable_id', 'language_id', 'url']);

        $currentUrls = [];
        foreach ($rows as $row) {
            // Match value(): the first matching row wins.
            if (is_object($row) && is_string($row->url ?? null)) {
                $currentUrls[(int) $row->pageable_id][(int) $row->language_id] ??= $row->url;
            }
        }

        return $currentUrls;
    }
}
