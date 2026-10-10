<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Illuminate\Database\MySqlConnection;
use Override;
use PDO;
use stdClass;

/** Legacy catalogue rows at the database boundary, with submitted DDL retained. */
final class LegacyTimestampConnection extends MySqlConnection
{
    /** @var list<string> */
    public array $statements = [];

    /** @param array<string, list<stdClass>> $columns */
    public function __construct(private readonly array $columns)
    {
        parent::__construct(new PDO('sqlite::memory:'), 'timestamp_proof', 'proof_', ['name' => 'timestamp_proof', 'version' => '8.0.0']);
    }

    /** @return list<stdClass> */
    #[Override]
    public function select(mixed $query, mixed $bindings = [], mixed $useReadPdo = true, array $fetchUsing = []): array
    {
        foreach ($this->columns as $table => $columns) {
            if (str_contains((string) $query, "table_name = 'proof_" . $table . "'")) {
                return $columns;
            }
        }

        return [];
    }

    #[Override]
    public function statement(mixed $query, mixed $bindings = []): bool
    {
        $this->statements[] = (string) $query;

        return true;
    }
}
