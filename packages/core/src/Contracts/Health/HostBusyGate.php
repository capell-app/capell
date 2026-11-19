<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Health;

/**
 * Lets scheduled work that can wait ask whether this server is busy. The
 * answer is always "run" unless deferring is turned on, a reading says the
 * server is critically busy, and the task has not already waited its limit.
 */
interface HostBusyGate
{
    /** True while this server is critically busy and deferring is turned on. False when there is no reading yet. */
    public function isBusy(): bool;

    /**
     * True when the named task should skip this run. A task deferred for longer than the configured
     * limit is released once, so a server that stays busy cannot starve it.
     */
    public function shouldDefer(string $task): bool;
}
