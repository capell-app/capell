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
use Capell\Frontend\Tests\Support\PublicReadTransitionSqsLikeQueue;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Connectors\ConnectorInterface as QueueConnector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * These tests drive the transition job through a real database queue and
 * worker, so uniqueness, releases, retries and deadlines behave as they do in
 * production rather than as a fake records them.
 */
function transitionJobQueue(): void
{
    // The jobs and failed_jobs tables come from the suite's migrations, never from DDL here:
    // on MySQL and MariaDB a CREATE TABLE commits the test's transaction and leaks rows.
    config()->set('queue.default', 'database');
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
    // Quoted as this connection quotes it: MySQL and MariaDB use backticks, SQLite and PostgreSQL double quotes.
    $revisionColumn = DB::connection()->getQueryGrammar()->wrap('revision');
    DB::beforeExecuting(static function (string $query) use ($failing, $revisionColumn): void {
        $sql = mb_strtolower(ltrim($query));

        throw_if($failing->on && str_starts_with($sql, 'update') && str_contains($sql, 'public_read_eligibility_state') && str_contains($sql, $revisionColumn), RuntimeException::class, 'The revision cannot be written.');
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

function transitionJobSqsQueue(bool $fifo = false, bool $broken = false): void
{
    transitionJobQueue();
    PublicReadTransitionSqsLikeQueue::$fifo = $fifo;
    PublicReadTransitionSqsLikeQueue::$broken = $broken;
    PublicReadTransitionSqsLikeQueue::$dispatches = 0;
    PublicReadTransitionSqsLikeQueue::$rejected = 0;
    PublicReadTransitionSqsLikeQueue::$delays = [];
    Queue::extend('sqs', static fn (): QueueConnector => new class implements QueueConnector
    {
        public function connect(array $config): PublicReadTransitionSqsLikeQueue
        {
            return new PublicReadTransitionSqsLikeQueue(DB::connection(), 'jobs', 'default', 90);
        }
    });
    config()->set('queue.connections.sqs-like', ['driver' => 'sqs', 'queue' => 'default']);
    config()->set('queue.default', 'sqs-like');
}

function transitionJobWorkSqs(): void
{
    Artisan::call('queue:work', ['connection' => 'sqs-like', '--once' => true, '--sleep' => 0, '--tries' => 1]);
}

it('steps a transition further out than an SQS delay allows, then records it once', function (bool $fifo): void {
    transitionJobSqsQueue(fifo: $fifo);
    $transition = CarbonImmutable::now()->addDays(2)->startOfSecond();
    transitionJobRules($transition);
    $recorded = transitionJobRecorded();

    transitionJobStamp();
    expect(transitionJobQueued())->toBe(1);

    // However early the queue hands it over, the job waits in steps the queue accepts.
    for ($step = 0; $step < 3; $step++) {
        $this->travel(15)->minutes();
        transitionJobWorkSqs();
    }

    $this->travelTo($transition);
    transitionJobWorkSqs();

    expect($recorded->count)->toBe(1)
        ->and(transitionJobQueued())->toBe(0)
        ->and(transitionJobFailed())->toBe(0)
        ->and(PublicReadTransitionSqsLikeQueue::$rejected)->toBe(0)
        ->and(max(PublicReadTransitionSqsLikeQueue::$delays))->toBeLessThanOrEqual(900);
})->with(['standard' => [false], 'FIFO' => [true]]);

it('tries a failing dispatch at most once a minute, not on every request', function (): void {
    transitionJobSqsQueue(broken: true);
    transitionJobRules(CarbonImmutable::now()->addHours(2)->startOfSecond());

    for ($request = 0; $request < 5; $request++) {
        transitionJobStamp();
    }

    expect(PublicReadTransitionSqsLikeQueue::$dispatches)->toBe(1);

    $this->travel(PublicReadEligibilityState::EXPIRY_DISPATCH_RETRY_SECONDS + 1)->seconds();
    transitionJobStamp();

    expect(PublicReadTransitionSqsLikeQueue::$dispatches)->toBe(2);
});

it('leaves cleanup to the reconciler on queues that run jobs in this process', function (string $driver): void {
    config()->set('queue.connections.in-process', ['driver' => $driver]);
    config()->set('queue.default', 'in-process');

    $transition = CarbonImmutable::now()->addHour()->startOfSecond();
    transitionJobRules($transition);
    $recorded = transitionJobRecorded();

    transitionJobStamp();

    expect(Cache::has(PublicReadEligibilityState::EXPIRY_SCHEDULED_PREFIX . $transition->getTimestamp()))->toBeFalse()
        ->and($recorded->count)->toBe(0);
})->with(['deferred', 'background']);
