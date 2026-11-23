<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Csv;

/**
 * Spreadsheet applications run a cell that starts with `=`, `+`, `-`, `@`, a tab or a
 * carriage return as a formula. Every CSV the admin panel writes passes its text cells
 * through here, and every importer of those files restores them, so a round trip is lossless.
 *
 * A cell is prefixed with a single quote when, after any quotes it already starts with, its
 * first character is one of those triggers. Counting existing quotes is what keeps the
 * restore unambiguous: `'=x` exports as `''=x` and comes back as `'=x`.
 */
final class CsvCell
{
    private const string TRIGGER = '/^\'*[=+\-@\t\r]/';

    private const string NEUTRALISED = '/^\'+[=+\-@\t\r]/';

    public static function neutralise(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match(self::TRIGGER, $value) === 1 ? "'" . $value : $value;
    }

    public static function restore(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match(self::NEUTRALISED, $value) === 1 ? substr($value, 1) : $value;
    }
}
