<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Override;
use PDO;
use stdClass;

/** A database boundary with catalogue metadata and the SQL submitted for execution. */
final class TimestampMetadataConnection extends MySqlConnection
{
    /** @var list<string> */
    public array $statements = [];

    /** @param array<string, mixed>|null $metadata */
    public function __construct(private readonly ?array $metadata)
    {
        parent::__construct(new PDO('sqlite::memory:'), 'timestamp_proof', 'proof_');
        $this->setQueryGrammar(new MySqlGrammar($this));
    }

    #[Override]
    public function selectOne(mixed $query, mixed $bindings = [], mixed $useReadPdo = true): ?stdClass
    {
        if ($bindings !== ['timestamp_proof', 'proof_events', 'occurred_at']) {
            return null;
        }

        return $this->metadata === null ? null : (object) $this->metadata;
    }

    #[Override]
    public function statement(mixed $query, mixed $bindings = []): bool
    {
        $this->statements[] = (string) $query;

        return true;
    }
}
