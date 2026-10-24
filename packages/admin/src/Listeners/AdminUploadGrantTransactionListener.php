<?php

declare(strict_types=1);

namespace Capell\Admin\Listeners;

use Capell\Admin\Support\Media\AdminUploadGrantRepository;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Stateless bridge from database transaction events to the request-scoped grant
 * journal. It is registered once per application and resolves the current scoped
 * repository when an event fires, so the long-lived dispatcher never retains a
 * discarded repository or its pending writes.
 */
final class AdminUploadGrantTransactionListener
{
    public function beginning(TransactionBeginning $event): void
    {
        resolve(AdminUploadGrantRepository::class)->beginning($event->connection);
    }

    public function committed(TransactionCommitted $event): void
    {
        resolve(AdminUploadGrantRepository::class)->committed($event->connection);
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        resolve(AdminUploadGrantRepository::class)->rolledBack($event->connection);
    }
}
