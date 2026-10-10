<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

final readonly class LegacyTimestampResolver
{
    public function __construct(private LegacyTimestampConnection $connection) {}

    public function connection(): LegacyTimestampConnection
    {
        return $this->connection;
    }
}
