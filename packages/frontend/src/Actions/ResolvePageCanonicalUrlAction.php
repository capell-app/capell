<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
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
            $canonicalPageUrl = $page->canonicalPage->pageUrls->firstWhere('language_id', $language->id);

            if ($canonicalPageUrl?->full_url !== null) {
                return $canonicalPageUrl->full_url;
            }
        }

        $pageUrl = $page->relationLoaded('pageUrl') ? $page->pageUrl : null;

        if ($page instanceof Page && $pageUrl?->type === UrlTypeEnum::Alias && $page->relationLoaded('pageUrls')) {
            $canonicalPageUrl = $page->pageUrls->firstWhere('language_id', $language->id);

            if ($canonicalPageUrl?->full_url !== null) {
                return $canonicalPageUrl->full_url;
            }
        }

        return $pageUrl?->full_url;
    }
}
