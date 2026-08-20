<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string|null run(Pageable $page, Language $language)
 */
final class ResolvePageCanonicalUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(Pageable $page, Language $language): ?string
    {
        $configuredUrl = data_get($page->meta ?? [], 'canonical_url');

        if (is_string($configuredUrl) && trim($configuredUrl) !== '') {
            return trim($configuredUrl);
        }

        if ($page instanceof Page && $page->relationLoaded('canonicalPage') && $page->canonicalPage instanceof Page && $page->canonicalPage->relationLoaded('pageUrls')) {
            $canonicalPageUrl = $this->canonicalPageUrl($page->canonicalPage, $language);

            if ($canonicalPageUrl?->full_url !== null) {
                return $canonicalPageUrl->full_url;
            }
        }

        $pageUrl = $page->relationLoaded('pageUrl') ? $page->pageUrl : null;

        if ($page instanceof Page && $pageUrl?->type === UrlTypeEnum::Alias && $page->relationLoaded('pageUrls')) {
            $canonicalPageUrl = $this->canonicalPageUrl($page, $language);

            if ($canonicalPageUrl?->full_url !== null) {
                return $canonicalPageUrl->full_url;
            }
        }

        return $pageUrl instanceof PageUrl && $pageUrl->type === null && $pageUrl->status
            ? $pageUrl->full_url
            : null;
    }

    private function canonicalPageUrl(Page $page, Language $language): ?PageUrl
    {
        return $page->pageUrls
            ->where('language_id', $language->id)
            ->whereNull('type')
            ->where('status', true)
            ->sortBy('id')
            ->first();
    }
}
