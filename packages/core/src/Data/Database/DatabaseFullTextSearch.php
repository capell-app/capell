<?php

declare(strict_types=1);

namespace Capell\Core\Data\Database;

final readonly class DatabaseFullTextSearch
{
    public function __construct(
        public SqlFragment $predicate,
        public SqlFragment $relevance,
        public bool $native,
    ) {}
}
