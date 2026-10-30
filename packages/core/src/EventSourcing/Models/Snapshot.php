<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Override;
use Spatie\EventSourcing\Snapshots\EloquentSnapshot;

class Snapshot extends EloquentSnapshot
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    #[Override]
    public function getTable(): string
    {
        $table = parent::getTable();

        return $table === 'snapshots' ? (string) config('capell.database.external_tables.snapshots', 'snapshots') : $table;
    }
}
