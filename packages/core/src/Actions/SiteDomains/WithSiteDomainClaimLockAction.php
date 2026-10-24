<?php

declare(strict_types=1);

namespace Capell\Core\Actions\SiteDomains;

use Closure;
use Illuminate\Database\Connection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** Serialise all origins together because wildcard claims can overlap any host/scheme. */
final class WithSiteDomainClaimLockAction
{
    use AsFake;
    use AsObject;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function handle(Connection $connection, Closure $operation): mixed
    {
        return $connection->transaction(function () use ($connection, $operation): mixed {
            // The migration seeds this row, so an empty claims table is protected too.
            // UPDATE also acquires a write lock on SQLite, where FOR UPDATE is ignored.
            $lock = $connection->table('site_domain_claim_locks')->where('id', 1);
            $lock->update(['id' => 1]);
            throw_unless($lock->exists(), RuntimeException::class, 'Site domain claim lock is missing; run Core migrations.');

            return $operation();
        });
    }
}
