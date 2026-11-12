<?php

declare(strict_types=1);

namespace Capell\Core\Data\Database;

/**
 * How a database column compares two strings for equality, so an application
 * key can collapse the values the database itself would treat as the same.
 */
final readonly class ColumnComparison
{
    public function __construct(
        public bool $caseInsensitive,
        public bool $accentInsensitive,
        public bool $padSpace,
    ) {}

    public static function exact(): self
    {
        return new self(caseInsensitive: false, accentInsensitive: false, padSpace: false);
    }

    /** The widest equality a MySQL-family server can apply, used when the collation cannot be read. */
    public static function widest(): self
    {
        return new self(caseInsensitive: true, accentInsensitive: true, padSpace: true);
    }

    public static function fromMySqlCollation(?string $collation): self
    {
        if ($collation === null || $collation === '') {
            return self::widest();
        }

        $collation = strtolower($collation);
        $caseInsensitive = str_ends_with($collation, '_ci');
        $accentInsensitive = str_contains($collation, '_ai_')
            || ($caseInsensitive && ! str_contains($collation, '_as_'));
        $noPad = str_contains($collation, 'nopad') || str_contains($collation, '_0900_');

        return new self($caseInsensitive, $accentInsensitive, ! $noPad);
    }
}
