<?php

declare(strict_types=1);

namespace Capell\Core\Actions\PublicRead;

use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Asks the public-read eligibility contributors about many served page
 * addresses in one batch. An address whose absolute URL cannot be built is
 * treated as not readable. Load siteDomain on the URLs to avoid one query each.
 *
 * @method static list<bool> run(iterable<PageUrl> $pageUrls)
 */
final class PageUrlsArePubliclyReadableAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly PublicPageReadEligibilityRegistry $registry) {}

    /**
     * @param  iterable<PageUrl>  $pageUrls
     * @return list<bool>
     */
    public function handle(iterable $pageUrls): array
    {
        $pageUrls = is_array($pageUrls) ? array_values($pageUrls) : iterator_to_array($pageUrls, false);

        if (! $this->registry->hasContributors()) {
            return array_fill(0, count($pageUrls), true);
        }

        $candidates = [];

        foreach ($pageUrls as $pageUrl) {
            try {
                $url = $pageUrl->fullUrl();
            } catch (Throwable) {
                $url = '';
            }

            $candidates[] = new PublicPageReadCandidateData(
                url: $url,
                siteId: $pageUrl->site_id,
                languageId: $pageUrl->language_id,
                pageableType: $pageUrl->pageable_type,
                pageableId: $pageUrl->pageable_id,
            );
        }

        // An empty URL is denied by the registry before any contributor sees it.
        return $this->registry->allowsMany($candidates);
    }
}
