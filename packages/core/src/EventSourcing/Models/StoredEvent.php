<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Override;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class StoredEvent extends EloquentStoredEvent
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    #[Override]
    public function getTable(): string
    {
        $table = parent::getTable();

        return $table === 'stored_events' ? (string) config('capell.database.external_tables.stored_events', 'stored_events') : $table;
    }
}
