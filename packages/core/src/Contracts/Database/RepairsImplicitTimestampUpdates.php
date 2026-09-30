<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Database;

use Illuminate\Database\Connection;

interface RepairsImplicitTimestampUpdates
{
    public function dropImplicitTimestampUpdate(string $table, string $column, Connection $connection): void;
}
