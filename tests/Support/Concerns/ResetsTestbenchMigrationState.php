<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Concerns;

/**
 * Opt a test file out of schema reuse.
 *
 * Package cases reuse one migrated in-memory schema per worker and wrap each test
 * in a transaction. A test that purges or swaps the default connection, vacuums,
 * or drives migrations itself would leave the next test on an empty or half-built
 * database, so such a file takes a fresh migration per test instead.
 *
 * A trait method replaces the inherited one, so this carries no #[Override].
 */
trait ResetsTestbenchMigrationState
{
    protected function shouldResetTestbenchMigrationState(): bool
    {
        return true;
    }
}
