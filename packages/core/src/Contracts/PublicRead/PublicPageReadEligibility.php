<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\PublicRead;

use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;

/**
 * Decides whether an anonymous visitor may read a public page at a URL.
 *
 * Public projections that list or describe pages outside the page's own
 * request (the agent API, sitemaps, feeds, search and AI discovery) cannot
 * run the page route's middleware. Packages that restrict a page route
 * contribute an implementation so those projections omit the page too.
 * Return false whenever the answer cannot be determined safely.
 *
 * @experimental
 */
interface PublicPageReadEligibility
{
    public function key(): string;

    public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool;
}
