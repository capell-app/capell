<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Capell\Core\Data\Properties\PropertyValueData;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Decides whether two property values are the same once stored, so every
 * path that asks "is this value unchanged?" (the page editor, page saves,
 * property writes and agent previews) gets the same answer: `5.50` and a
 * stored `5.5` are the same number, and two spellings of one moment are the
 * same date.
 */
final class PropertyValueEquality
{
    private const array NUMBER_COLUMNS = ['value_number'];

    private const array ID_COLUMNS = ['term_id', 'referenced_page_id', 'media_id', 'translation_id', 'position'];

    /**
     * Compare two stored-column arrays ({@see PropertyValueData::toColumns()}).
     *
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $other
     */
    public static function sameColumns(array $columns, array $other): bool
    {
        return self::normaliseColumns($columns) === self::normaliseColumns($other);
    }

    /**
     * Compare two already-coerced scalar values of one property.
     */
    public static function sameValue(mixed $value, mixed $other): bool
    {
        return self::scalar($value) === self::scalar($other);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private static function normaliseColumns(array $columns): array
    {
        $normalised = [];

        foreach ($columns as $column => $value) {
            $normalised[$column] = match (true) {
                in_array($column, self::NUMBER_COLUMNS, true) => is_numeric($value) ? (float) $value : null,
                in_array($column, self::ID_COLUMNS, true) => is_numeric($value) ? (int) $value : null,
                $column === 'value_datetime' => self::moment($value),
                default => $value,
            };
        }

        ksort($normalised);

        return $normalised;
    }

    private static function scalar(mixed $value): mixed
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            $value instanceof DateTimeInterface => self::moment($value),
            default => $value,
        };
    }

    private static function moment(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : (string) $value)
                ->utc()
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return is_scalar($value) ? (string) $value : null;
        }
    }
}
