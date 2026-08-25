<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\DescendantUrlRedirectRequestData;
use Capell\Admin\Data\Pages\PageUrlRedirectResultData;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
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
        $descendants = $request->page->newQuery()->whereDescendantOf($request->page->getKey())->get()
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

        $acceptedCount = 0;
        $recordedCount = 0;

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

                if (! $this->urlChangedFor($descendant, $languageId, $oldUrl)) {
                    continue;
                }

                $this->redirects->record($descendant, $language, $oldUrl);
                $recordedCount++;
            }
        }

        return new PageUrlRedirectResultData(
            acceptedCount: $acceptedCount,
            recordedCount: $recordedCount,
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
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $descendant
     */
    private function urlChangedFor(Pageable $descendant, int $languageId, string $oldUrl): bool
    {
        $currentUrl = $descendant->pageUrls()
            ->where('language_id', $languageId)
            ->where(fn (Builder $query): Builder => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->value('url');

        return ! is_string($currentUrl) || $currentUrl !== $oldUrl;
    }
}
