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
 * Candidates reach a contributor with absolute URLs: the registry resolves a
 * relative address against its site's domain first, and denies it when it
 * cannot. Use EvaluatesPublicPageReadCandidatesIndividually when there is
 * no cheaper batch evaluation than one candidate at a time.
 *
 * When a contributor's decisions change without a page or URL being saved
 * (a policy edit, a scheduled opening), it must record the change with
 * RecordPublicReadEligibilityChangeAction so stored projections are withdrawn.
 *
 * @experimental
 */
interface PublicPageReadEligibility
{
    public function key(): string;

    public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool;

    /**
     * @param  list<PublicPageReadCandidateData>  $candidates
     * @return list<bool> one decision per candidate, in order
     */
    public function arePubliclyReadable(array $candidates): array;
}
