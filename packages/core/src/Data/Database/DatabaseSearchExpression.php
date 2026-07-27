<?php

declare(strict_types=1);

namespace Capell\Core\Data\Database;

use InvalidArgumentException;

final readonly class DatabaseSearchExpression
{
    public function __construct(
        public SqlFragment $expression,
        public float $weight = 1.0,
    ) {
        throw_unless(
            is_finite($weight) && $weight >= 0,
            InvalidArgumentException::class,
            'Database search expression weights must be non-negative and finite.',
        );
    }
}
