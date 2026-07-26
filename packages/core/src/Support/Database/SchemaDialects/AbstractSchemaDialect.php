<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\SchemaDialects;

use Capell\Core\Data\Database\DatabaseIndexDefinition;
use InvalidArgumentException;

abstract class AbstractSchemaDialect
{
    protected function identifier(string $identifier, string $quote): string
    {
        throw_unless(
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1,
            InvalidArgumentException::class,
            sprintf('Unsafe database identifier [%s].', $identifier),
        );

        return $quote . $identifier . $quote;
    }

    protected function indexKeyword(DatabaseIndexDefinition $index): string
    {
        return $index->unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
    }

    protected function columnType(string $type): string
    {
        throw_unless(
            preg_match('/^[A-Z][A-Z0-9_]*(?:\\([0-9, ]+\\))?$/i', $type) === 1,
            InvalidArgumentException::class,
            sprintf('Unsafe generated column type [%s].', $type),
        );

        return strtoupper($type);
    }

    protected function stringLiteral(string $value): string
    {
        throw_if(str_contains($value, "\0"), InvalidArgumentException::class, 'Database string literals cannot contain null bytes.');

        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function jsonPathLiteral(string $path): string
    {
        throw_unless(
            preg_match('/^\\$(?:(?:\\.[A-Za-z_][A-Za-z0-9_]*)|\\[(?:\\d+|\\*)\\])*$/', $path) === 1,
            InvalidArgumentException::class,
            sprintf('Unsafe JSON path [%s].', $path),
        );

        return $this->stringLiteral($path);
    }
}
