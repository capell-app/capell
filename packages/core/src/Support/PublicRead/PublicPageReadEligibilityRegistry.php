<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Throwable;

/**
 * Collects public-read eligibility decisions from packages.
 *
 * A page is publicly readable only when every contributor allows it. A
 * contributor that throws, or answers for the wrong number of candidates,
 * denies the whole batch, so a projection omits pages rather than publishing
 * an address whose access rules could not be evaluated.
 *
 * Contributors always receive absolute URLs. A relative address is resolved
 * against the active domain of its site and language before any contributor
 * sees it; one that cannot be resolved is denied.
 *
 * @experimental
 *
 * @extends AbstractKeyedRegistry<PublicPageReadEligibility>
 */
final class PublicPageReadEligibilityRegistry extends AbstractKeyedRegistry
{
    public function register(PublicPageReadEligibility $eligibility): self
    {
        $this->setItem($eligibility->key(), $eligibility);

        return $this;
    }

    public function hasContributors(): bool
    {
        return $this->allItems() !== [];
    }

    public function allows(PublicPageReadCandidateData $candidate): bool
    {
        return $this->allowsMany([$candidate])[0];
    }

    /**
     * @param  list<PublicPageReadCandidateData>  $candidates
     * @return list<bool> one decision per candidate, in order
     */
    public function allowsMany(array $candidates): array
    {
        $candidates = array_values($candidates);

        if ($candidates === []) {
            return [];
        }

        if (! $this->hasContributors()) {
            return array_fill(0, count($candidates), true);
        }

        $absolute = $this->absoluteCandidates($candidates);
        $decisions = array_map(static fn (?PublicPageReadCandidateData $candidate): bool => $candidate instanceof PublicPageReadCandidateData, $absolute);
        $indexes = array_keys(array_filter($absolute, static fn (?PublicPageReadCandidateData $candidate): bool => $candidate instanceof PublicPageReadCandidateData));

        if ($indexes === []) {
            return $decisions;
        }

        /** @var list<PublicPageReadCandidateData> $evaluated */
        $evaluated = array_values(array_filter($absolute, static fn (?PublicPageReadCandidateData $candidate): bool => $candidate instanceof PublicPageReadCandidateData));

        foreach ($this->allItems() as $eligibility) {
            try {
                $answers = $eligibility->arePubliclyReadable($evaluated);
            } catch (Throwable $throwable) {
                report($throwable);

                return array_fill(0, count($candidates), false);
            }

            if (count($answers) !== count($evaluated)) {
                return array_fill(0, count($candidates), false);
            }

            foreach (array_values($answers) as $position => $answer) {
                if ($answer !== true) {
                    $decisions[$indexes[$position]] = false;
                }
            }
        }

        return array_values($decisions);
    }

    /**
     * @param  list<PublicPageReadCandidateData>  $candidates
     * @return list<PublicPageReadCandidateData|null>
     */
    private function absoluteCandidates(array $candidates): array
    {
        $siteIds = [];

        foreach ($candidates as $candidate) {
            if (! $this->isAbsolute($candidate->url) && $candidate->siteId !== null) {
                $siteIds[$candidate->siteId] = true;
            }
        }

        $domains = $siteIds === [] ? collect() : SiteDomain::query()
            ->whereIn('site_id', array_keys($siteIds))
            ->where('status', true)
            ->orderBy('id')
            ->get();

        return array_map(function (PublicPageReadCandidateData $candidate) use ($domains): ?PublicPageReadCandidateData {
            $url = trim($candidate->url);

            if ($url === '') {
                return null;
            }

            if ($this->isAbsolute($url)) {
                return $candidate;
            }

            if ($candidate->siteId === null || str_starts_with($url, '//')) {
                return null;
            }

            $siteDomains = $domains->where('site_id', $candidate->siteId);
            $domain = $siteDomains->firstWhere('language_id', $candidate->languageId) ?? $siteDomains->first();

            if (! $domain instanceof SiteDomain) {
                return null;
            }

            return new PublicPageReadCandidateData(
                url: rtrim((string) $domain->full_url, '/') . '/' . ltrim($url, '/'),
                siteId: $candidate->siteId,
                languageId: $candidate->languageId,
                pageableType: $candidate->pageableType,
                pageableId: $candidate->pageableId,
            );
        }, $candidates);
    }

    private function isAbsolute(string $url): bool
    {
        $scheme = parse_url(trim($url), PHP_URL_SCHEME);
        $host = parse_url(trim($url), PHP_URL_HOST);

        return in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true) && is_string($host) && $host !== '';
    }
}
