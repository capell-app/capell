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
 * Asks the public-read eligibility contributors about a served page address.
 * An address whose absolute URL cannot be built is treated as not readable.
 *
 * @method static bool run(PageUrl $pageUrl)
 */
final class PageUrlIsPubliclyReadableAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly PublicPageReadEligibilityRegistry $registry) {}

    public function handle(PageUrl $pageUrl): bool
    {
        if (! $this->registry->hasContributors()) {
            return true;
        }

        try {
            $url = $pageUrl->fullUrl();
        } catch (Throwable) {
            return false;
        }

        return $this->registry->allows(new PublicPageReadCandidateData(
            url: $url,
            siteId: $pageUrl->site_id,
            languageId: $pageUrl->language_id,
            pageableType: $pageUrl->pageable_type,
            pageableId: $pageUrl->pageable_id,
        ));
    }
}
