<?php

declare(strict_types=1);

namespace Capell\Core\Data\Database;

use InvalidArgumentException;

final readonly class DatabaseIndexDefinition
{
    /**
     * @param  non-empty-list<non-empty-string>  $columns
     * @param  array<non-empty-string, positive-int>  $prefixLengths
     */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public array $prefixLengths = [],
        public bool $unique = false,
    ) {
        throw_if(trim($table) === '' || trim($name) === '' || $columns === [], InvalidArgumentException::class, 'Database indexes require a table, name, and columns.');

        foreach ($prefixLengths as $column => $length) {
            throw_unless(in_array($column, $columns, true) && $length > 0, InvalidArgumentException::class, 'Index prefixes must reference an indexed column and use a positive length.');
        }
    }
}
