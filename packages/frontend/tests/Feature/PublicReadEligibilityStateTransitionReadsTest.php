<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Contracts\PublicRead\SchedulesPublicReadTransitions;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

function countingTransitionRules(array $schedule): CountingTransitionRules
{
    config()->set('queue.default', 'sync');
    $rules = new CountingTransitionRules($schedule);
    resolve(PublicPageReadEligibilityRegistry::class)->register($rules);
    app()->forgetScopedInstances();
    resolve(PublicReadEligibilityRevision::class)->advance();
    app()->forgetScopedInstances();

    return $rules;
}

function transitionReadsState(): PublicReadEligibilityState
{
    return resolve(PublicReadEligibilityState::class);
}

it('reads a site schedule once per request however often the request stamps it', function (): void {
    $opening = CarbonImmutable::now()->addHour()->startOfSecond();
    $rules = countingTransitionRules(['7' => [$opening]]);

    $stamps = [transitionReadsState()->stamp(7), transitionReadsState()->stamp(7), transitionReadsState()->stamp(7)];

    expect($rules->reads)->toBe(1);

    foreach ($stamps as $stamp) {
        expect($stamp?->validUntil?->equalTo($opening))->toBeTrue();
    }
});

it('keeps each site and the every-site bound apart', function (): void {
    $siteSeven = CarbonImmutable::now()->addHour()->startOfSecond();
    $siteEight = CarbonImmutable::now()->addMinutes(10)->startOfSecond();
    $rules = countingTransitionRules(['7' => [$siteSeven], '8' => [$siteEight]]);

    $seven = transitionReadsState()->stamp(7);
    $eight = transitionReadsState()->stamp(8);
    $any = transitionReadsState()->stamp();
    transitionReadsState()->stamp(7);
    transitionReadsState()->stamp(8);
    transitionReadsState()->stamp();

    expect($rules->reads)->toBe(3)
        ->and($seven?->validUntil?->equalTo($siteSeven))->toBeTrue()
        ->and($eight?->validUntil?->equalTo($siteEight))->toBeTrue()
        ->and($any?->validUntil?->equalTo($siteEight))->toBeTrue();
});

it('reads the schedule again once a remembered transition is reached', function (): void {
    $opening = CarbonImmutable::now()->addMinutes(5)->startOfSecond();
    $closing = $opening->addHour();
    $rules = countingTransitionRules(['7' => [$opening, $closing]]);

    expect(transitionReadsState()->stamp(7)?->validUntil?->equalTo($opening))->toBeTrue();

    // Same request, past the opening: the remembered answer no longer describes now.
    $this->travelTo($opening);

    expect(transitionReadsState()->stamp(7)?->validUntil?->equalTo($closing))->toBeTrue()
        ->and($rules->reads)->toBe(2);

    $this->travelTo($closing->addSecond());

    expect(transitionReadsState()->stamp(7)?->validUntil)->toBeNull()
        ->and($rules->reads)->toBe(3);
});

it('answers exactly what an unremembered read answers at every moment', function (): void {
    $start = CarbonImmutable::now()->startOfSecond();
    $moments = [$start->addMinutes(3), $start->addMinutes(9), $start->addMinutes(20)];
    countingTransitionRules(['7' => $moments]);
    $registry = resolve(PublicPageReadEligibilityRegistry::class);

    foreach (range(0, 25) as $minute) {
        $this->travelTo($start->addMinutes($minute));
        $expected = $registry->nextTransitionAfter(CarbonImmutable::now(), 7);
        $remembered = transitionReadsState()->stamp(7)?->validUntil;

        expect($remembered?->getTimestamp())->toBe($expected?->getTimestamp(), 'minute ' . $minute);
    }
});

it('never remembers a schedule that could not be read', function (): void {
    $this->freezeTime();
    $rules = countingTransitionRules(['7' => [CarbonImmutable::now()->addHour()]]);
    $rules->failing = true;

    // A failed read answers "now": everything stamped is already expired.
    $first = transitionReadsState()->stamp(7);
    $second = transitionReadsState()->stamp(7);

    expect($first?->validUntil?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($second?->validUntil?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($rules->reads)->toBe(2);

    $rules->failing = false;

    expect(transitionReadsState()->stamp(7)?->validUntil?->greaterThan(CarbonImmutable::now()))->toBeTrue()
        ->and($rules->reads)->toBe(3);
});

it('stores nothing when the revision row cannot be read', function (): void {
    $rules = countingTransitionRules(['7' => [CarbonImmutable::now()->addHour()]]);
    DB::table('public_read_eligibility_state')->where('id', 1)->update(['unknown_token' => 'failed-advance']);
    Cache::put('capell:public-read-eligibility:revision-retry', true, 60);
    app()->forgetScopedInstances();

    expect(transitionReadsState()->stamp(7))->toBeNull()
        ->and(transitionReadsState()->stamp(7))->toBeNull()
        ->and($rules->reads)->toBe(0);
});

it('reads the schedule again for a fresh stamp that fences a generation', function (): void {
    $opening = CarbonImmutable::now()->addHour()->startOfSecond();
    $rules = countingTransitionRules(['7' => [$opening]]);

    transitionReadsState()->stamp(7);
    $earlier = CarbonImmutable::now()->addMinutes(10)->startOfSecond();
    $rules->schedule(7, $earlier);

    expect(transitionReadsState()->freshStamp(7)?->validUntil?->equalTo($earlier))->toBeTrue()
        ->and($rules->reads)->toBe(2)
        // The fence's read is what the rest of the request now uses.
        ->and(transitionReadsState()->stamp(7)?->validUntil?->equalTo($earlier))->toBeTrue()
        ->and($rules->reads)->toBe(2);
});

it('forgets every remembered schedule between requests, as Octane flushes scoped instances', function (): void {
    $opening = CarbonImmutable::now()->addHour()->startOfSecond();
    $rules = countingTransitionRules(['7' => [$opening]]);

    transitionReadsState()->stamp(7);
    $first = transitionReadsState();

    // The next request (Octane worker or queued job) gets a new state.
    app()->forgetScopedInstances();
    $earlier = CarbonImmutable::now()->addMinutes(10)->startOfSecond();
    $rules->schedule(7, $earlier);

    expect(transitionReadsState())->not->toBe($first)
        ->and(transitionReadsState()->stamp(7)?->validUntil?->equalTo($earlier))->toBeTrue()
        ->and($rules->reads)->toBe(2);
});
