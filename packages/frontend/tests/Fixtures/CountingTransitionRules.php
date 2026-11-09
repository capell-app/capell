<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Contracts\PublicRead\SchedulesPublicReadTransitions;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A contributor whose schedule is a list of transition moments (optionally
 * per site) and which counts how often it is asked, or fails on demand.
 */
final class CountingTransitionRules implements PublicPageReadEligibility, SchedulesPublicReadTransitions
{
    use EvaluatesPublicPageReadCandidatesIndividually;

    public int $reads = 0;

    public bool $failing = false;

    /** @param array<int|string, list<CarbonImmutable>> $schedule keyed by site id, or '*' for every site */
    public function __construct(private array $schedule) {}

    public function schedule(int|string $site, CarbonImmutable $moment): void
    {
        $this->schedule[$site][] = $moment;
    }

    public function key(): string
    {
        return 'counting-transition-rules';
    }

    public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
    {
        return true;
    }

    public function nextPublicReadTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable
    {
        $this->reads++;

        throw_if($this->failing, RuntimeException::class, 'Schedule unavailable.');

        $moments = [...($this->schedule['*'] ?? []), ...($siteId === null ? array_merge(...array_values($this->schedule)) : ($this->schedule[(string) $siteId] ?? []))];
        $ahead = array_filter($moments, static fn (CarbonImmutable $moment): bool => $moment->greaterThan($now));
        usort($ahead, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $ahead[0] ?? null;
    }
}
