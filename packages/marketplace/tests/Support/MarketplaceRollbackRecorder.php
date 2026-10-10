<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Throwable;

/**
 * A stand-in for RestoreComposerStateAction that records what it was asked to
 * restore instead of running a real recovery `composer install`. Named rather
 * than anonymous so the recordings are typed properties the whole suite can read.
 *
 * The parameters are kept, and recorded, deliberately. PHP tolerates extra
 * arguments to a handle() that declares none, so dropping them would silently
 * sever the only place where the snapshot and the rollback budget reaching
 * RestoreComposerStateAction can be observed — and a job that passed the wrong
 * budget, or no snapshot, would keep every one of these tests green.
 */
final class MarketplaceRollbackRecorder
{
    public int $calls = 0;

    /** @var list<ComposerStateSnapshot> */
    public array $snapshots = [];

    /** @var list<int> */
    public array $timeoutSeconds = [];

    public function __construct(private readonly ?Throwable $rollbackFailure = null) {}

    public function handle(
        ComposerStateSnapshot $snapshot,
        int $timeoutSeconds = ComposerStateSnapshot::DEFAULT_TIMEOUT_SECONDS,
    ): bool {
        $this->calls++;
        $this->snapshots[] = $snapshot;
        $this->timeoutSeconds[] = $timeoutSeconds;

        if ($this->rollbackFailure instanceof Throwable) {
            throw $this->rollbackFailure;
        }

        return true;
    }
}
