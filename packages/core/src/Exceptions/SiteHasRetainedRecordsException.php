<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/** Refuse a permanent site deletion that would cascade away records that must be kept. */
final class SiteHasRetainedRecordsException extends RuntimeException
{
    /** @param  array<string, int>  $retained */
    public static function forRecords(array $retained): self
    {
        $kinds = implode(', ', array_map(
            static fn (string $description, int $count): string => $count . ' ' . $description,
            array_keys($retained),
            $retained,
        ));

        return new self((string) __('capell-core::message.site_has_retained_records', ['records' => $kinds]));
    }
}
