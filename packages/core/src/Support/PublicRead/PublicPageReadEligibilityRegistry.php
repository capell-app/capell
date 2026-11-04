<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Contracts\PublicRead\SchedulesPublicReadTransitions;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Carbon\CarbonImmutable;
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
 * against the domain it is published on (the candidate's publishedOn) before
 * any contributor sees it; one without that domain is denied. While packages
 * restrict reads, a revision that cannot be read denies everything.
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

        // Rules whose revision cannot be read cannot be trusted to be current.
        if (resolve(PublicReadEligibilityRevision::class)->current() === null) {
            return array_fill(0, count($candidates), false);
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
     * The earliest scheduled moment after $now at which any contributor's
     * decisions may change. A contributor that fails answers $now, so nothing
     * built from its rules is stored.
     */
    public function nextTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable
    {
        $next = null;

        foreach ($this->allItems() as $eligibility) {
            if (! $eligibility instanceof SchedulesPublicReadTransitions) {
                continue;
            }

            try {
                $transition = $eligibility->nextPublicReadTransitionAfter($now, $siteId);
            } catch (Throwable $throwable) {
                report($throwable);

                return $now;
            }

            if ($transition instanceof CarbonImmutable && (! $next instanceof CarbonImmutable || $transition->lessThan($next))) {
                $next = $transition;
            }
        }

        return $next;
    }

    /**
     * @param  list<PublicPageReadCandidateData>  $candidates
     * @return list<PublicPageReadCandidateData|null>
     */
    private function absoluteCandidates(array $candidates): array
    {
        return array_map(function (PublicPageReadCandidateData $candidate): ?PublicPageReadCandidateData {
            $url = trim($candidate->url);

            if ($url === '') {
                return null;
            }

            if ($this->isAbsolute($url)) {
                return $candidate;
            }

            // A relative address belongs to the domain it is published on; without one it is denied.
            if ($candidate->publishedOn === null || ! $this->isAbsolute($candidate->publishedOn) || str_starts_with($url, '//')) {
                return null;
            }

            return new PublicPageReadCandidateData(
                url: rtrim($candidate->publishedOn, '/') . '/' . ltrim($url, '/'),
                siteId: $candidate->siteId,
                languageId: $candidate->languageId,
                pageableType: $candidate->pageableType,
                pageableId: $candidate->pageableId,
                publishedOn: $candidate->publishedOn,
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
