<?php

declare(strict_types=1);

namespace Capell\Core\Jobs\PublicRead;

use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Runs at a scheduled public-read transition (an access area opening or
 * closing) and records it as an eligibility change, so cached HTML, static
 * exports and every other stored projection are withdrawn at that moment
 * rather than at the next scheduler tick.
 *
 * Cleanup only: projections already stop being served at the transition on
 * their own, and the public-read reconciler (every minute) withdraws whatever
 * this job does not.
 *
 * One mechanism keeps it to a single recording per transition, without a
 * queue unique lock: PublicReadEligibilityState admits one dispatch per
 * transition through a cache marker that lives until just after it, and a
 * job finding the revision already advanced at or after its transition
 * (by a duplicate dispatched after a lost marker, an earlier attempt or any
 * other change) does nothing. Retries therefore converge.
 *
 * Its budget is a deadline taken from the transition, not an attempt count:
 * a queue that delivers it early, or cannot delay it that long, releases
 * it towards the transition in steps it accepts without using anything
 * up; a failed recording is retried with backoff until the deadline; and
 * a job still not done by then is failed and logged.
 *
 * @experimental
 */
final class ExpirePublicReadProjectionsJob implements ShouldQueue
{
    use Queueable;

    /** How long after its transition the job may still record it. */
    public const int DEADLINE_SECONDS = 3600;

    public const int SQS_MAX_DELAY_SECONDS = 900;

    // Unlimited attempts: early deliveries are releases, not failures. retryUntil() and $maxExceptions bound it.
    public int $tries = 0;

    public int $maxExceptions = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public readonly int $transitionAt) {}

    /**
     * The longest delay or release the connection accepts, or null when it
     * has no limit. Amazon SQS rejects a delay above 15 minutes (a FIFO queue
     * ignores it) and a release above 12 hours, so the job waits there in
     * 15-minute steps.
     */
    public static function maxDelayFor(?string $connection): ?int
    {
        $connection ??= config('queue.default');
        $driver = is_string($connection) ? config('queue.connections.' . $connection . '.driver') : null;

        return $driver === 'sqs' ? self::SQS_MAX_DELAY_SECONDS : null;
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestamp($this->transitionAt)->addSeconds(self::DEADLINE_SECONDS);
    }

    public function handle(): void
    {
        $now = CarbonImmutable::now()->getTimestamp();

        // A queue that runs a job early must not record the transition before it happens.
        if ($now < $this->transitionAt) {
            $wait = $this->transitionAt - $now;
            $maxDelay = self::maxDelayFor($this->job?->getConnectionName());
            $this->release($maxDelay === null ? $wait : min($wait, $maxDelay));

            return;
        }

        if (resolve(PublicReadEligibilityRevision::class)->advancedSince($this->transitionAt)) {
            return;
        }

        $revision = RecordPublicReadEligibilityChangeAction::run('public-read.scheduled-transition');

        throw_if($revision === null, RuntimeException::class, 'The public-read eligibility revision could not be advanced at the scheduled transition.');
    }

    /**
     * Nothing to undo: the job holds no lock or claim. Reads stay denied while
     * the revision is unknown, and the reconciler withdraws what is left.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('A scheduled public-read transition was not recorded by its job; the public-read reconciler will withdraw stale copies instead.', [
            'transition_at' => CarbonImmutable::createFromTimestamp($this->transitionAt, date_default_timezone_get())->toIso8601String(),
            'exception' => $exception instanceof Throwable ? $exception::class . ': ' . $exception->getMessage() : null,
        ]);
    }
}
