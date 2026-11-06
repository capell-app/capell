<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Illuminate\Queue\DatabaseQueue;
use Override;
use RuntimeException;

/**
 * A database-backed queue with Amazon SQS's limits: a delay above 900 seconds
 * is rejected, a FIFO queue ignores the delay, and a release (a visibility
 * change) above 12 hours is rejected. $broken makes every dispatch fail.
 */
final class PublicReadTransitionSqsLikeQueue extends DatabaseQueue
{
    public static bool $fifo = false;

    public static bool $broken = false;

    public static int $dispatches = 0;

    public static int $rejected = 0;

    /** @var list<int> */
    public static array $delays = [];

    #[Override]
    public function later($delay, $job, $data = '', $queue = null)
    {
        self::$dispatches++;
        throw_if(self::$broken, RuntimeException::class, 'The queue is unavailable.');
        $seconds = $this->secondsUntil($delay);
        if (! self::$fifo && $seconds > 900) {
            self::$rejected++;

            throw new RuntimeException('Value for parameter DelaySeconds is invalid.');
        }

        self::$delays[] = self::$fifo ? 0 : $seconds;

        return parent::later(self::$fifo ? 0 : $delay, $job, $data, $queue);
    }

    #[Override]
    public function release($queue, $job, $delay)
    {
        if ($delay > 43200) {
            self::$rejected++;

            throw new RuntimeException('Value for parameter VisibilityTimeout is invalid.');
        }

        self::$delays[] = $delay;

        return parent::release($queue, $job, $delay);
    }
}
