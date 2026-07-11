<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class MigrationPublishCommandResultData extends Data
{
    /**
     * @param  list<string>  $lines
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $applied,
        public readonly int $blocked,
        public readonly array $lines = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {}

    public function successful(): bool
    {
        return $this->errors === [];
    }
}
