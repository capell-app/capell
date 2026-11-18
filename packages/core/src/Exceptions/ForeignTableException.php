<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

final class ForeignTableException extends RuntimeException
{
    /**
     * @param  array<string, string>  $replacements
     */
    public static function forTable(array $replacements, bool $external): self
    {
        return new self($external
            ? (string) __('capell-core::install.tables.foreign_external_table', $replacements)
            : (string) __('capell-core::install.tables.foreign_table', $replacements));
    }
}
