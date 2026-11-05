<?php

declare(strict_types=1);

use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Contracts\PublicRead\SchedulesPublicReadTransitions;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Events\PublicReadEligibilityChanged;
use Capell\Core\Jobs\PublicRead\ExpirePublicReadProjectionsJob;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * These tests drive the transition job through a real database queue and
 * worker, so uniqueness, releases, retries and deadlines behave as they do in
 * production rather than as a fake records them.
 */
function transitionJobQueue(): void
{
    config()->set('queue.default', 'database');

    if (! Schema::hasTable('jobs')) {
        (require dirname(__DIR__, 4) . '/vendor/orchestra/testbench-core/laravel/migrations/0001_01_01_000002_testbench_create_jobs_table.php')->up();
    }
}

function transitionJobRules(CarbonImmutable $transition): void
{
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($transition) implements PublicPageReadEligibility, SchedulesPublicReadTransitions
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function __construct(private CarbonImmutable $transition) {}

        public function key(): string
        {
            return 'transition-job-test';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function nextPublicReadTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable
        {
            return $now->lessThan($this->transition) ? $this->transition : null;
        }
    });
}

/** Counts scheduled-transition changes recorded from now on. */
function transitionJobRecorded(): stdClass
{
    $recorded = new stdClass;
    $recorded->count = 0;

    Event::listen(PublicReadEligibilityChanged::class, static function (PublicReadEligibilityChanged $event) use ($recorded): void {
        if ($event->source === 'public-read.scheduled-transition') {
            $recorded->count++;
        }
    });

    return $recorded;
}

/** Stamps a projection in a fresh request, which schedules the transition's cleanup. */
function transitionJobStamp(): void
{
    app()->forgetScopedInstances();
    resolve(PublicReadEligibilityState::class)->stamp();
}

function transitionJobWork(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]);
}

function transitionJobQueued(): int
{
    return DB::table('jobs')->count();
}

function transitionJobFailed(): int
{
    return DB::table('failed_jobs')->count();
}

it('records a transition once even when a lost marker queued it twice', function (): void {
    transitionJobQueue();
    $transition = CarbonImmutable::now()->addHours(2)->startOfSecond();
    transitionJobRules($transition);
    $recorded = transitionJobRecorded();

    transitionJobStamp();
    transitionJobStamp();
    expect(transitionJobQueued())->toBe(1);

    // A cache clear loses the once-per-transition marker, so a later request queues it again.
    Cache::flush();
    transitionJobStamp();
    expect(transitionJobQueued())->toBe(2);

    $this->travelTo($transition);
    transitionJobWork();
    transitionJobWork();

    expect($recorded->count)->toBe(1)
        ->and(transitionJobQueued())->toBe(0)
        ->and(transitionJobFailed())->toBe(0);
});

it('waits for its transition when the queue delivers it early, without using up its attempts', function (): void {
    transitionJobQueue();
    $transition = CarbonImmutable::now()->addMinutes(20)->startOfSecond();
    $recorded = transitionJobRecorded();

    // Some queues cap how far ahead a job may be delayed and deliver it early.
    Queue::connection('database')->push(new ExpirePublicReadProjectionsJob($transition->getTimestamp()));
    transitionJobWork();

    expect($recorded->count)->toBe(0)
        ->and(transitionJobQueued())->toBe(1);

    $this->travelTo($transition);
    transitionJobWork();

    expect($recorded->count)->toBe(1)
        ->and(transitionJobQueued())->toBe(0)
        ->and(transitionJobFailed())->toBe(0);
});

it('retries a transition whose revision could not be stored, until it is', function (): void {
    transitionJobQueue();
    $transition = CarbonImmutable::now()->addMinutes(5)->startOfSecond();
    transitionJobRules($transition);
    transitionJobStamp();
    $recorded = transitionJobRecorded();

    $failing = new stdClass;
    $failing->on = true;
    DB::beforeExecuting(static function (string $query) use ($failing): void {
        $sql = mb_strtolower(ltrim($query));

        throw_if($failing->on && str_starts_with($sql, 'update') && str_contains($sql, 'public_read_eligibility_state') && str_contains($sql, '"revision"'), RuntimeException::class, 'The revision cannot be written.');
    });

    $this->travelTo($transition);
    transitionJobWork();

    // Not recorded, so not finished: the job stays queued for another attempt.
    expect(transitionJobQueued())->toBe(1)
        ->and(transitionJobFailed())->toBe(0);

    $failing->on = false;
    $this->travel(5)->minutes();
    app()->forgetScopedInstances();
    transitionJobWork();

    expect(transitionJobQueued())->toBe(0)
        ->and(transitionJobFailed())->toBe(0)
        ->and($recorded->count)->toBe(2);
});

it('gives up at a deadline taken from its transition and says so', function (): void {
    transitionJobQueue();
    $transition = CarbonImmutable::now()->addDays(3)->startOfSecond();
    transitionJobRules($transition);
    transitionJobStamp();
    $recorded = transitionJobRecorded();
    $log = Log::spy();

    // The deadline follows the transition, not the dispatch: three days out, the job is still live.
    $this->travelTo($transition->addMinutes(30));
    expect(transitionJobQueued())->toBe(1);

    // No worker ran until long after the transition.
    $this->travelTo($transition->addSeconds(ExpirePublicReadProjectionsJob::DEADLINE_SECONDS + 60));
    transitionJobWork();

    expect($recorded->count)->toBe(0)
        ->and(transitionJobQueued())->toBe(0)
        ->and(transitionJobFailed())->toBe(1);

    $log->shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $context['transition_at'] === $transition->toIso8601String())->once();
});
