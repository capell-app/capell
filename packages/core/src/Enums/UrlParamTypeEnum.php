<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum UrlParamTypeEnum: string
{
    case Int = 'int';
    case String = 'string';
    case Date = 'date';

    public static function coerceByType(string $rawValue, string $type): int|string|null
    {
        $resolvedType = self::tryFrom($type);

        return $resolvedType?->coerce($rawValue);
    }

    public function coerce(string $rawValue): int|string|null
    {
        return match ($this) {
            self::Int => self::coerceInteger($rawValue),
            self::String => self::coerceString($rawValue),
            self::Date => self::coerceDate($rawValue),
        };
    }

    private static function coerceInteger(string $rawValue): ?int
    {
        if (preg_match('/^-?\\d+$/', $rawValue) !== 1) {
            return null;
        }

        return (int) $rawValue;
    }

    private static function coerceString(string $rawValue): ?string
    {
        return $rawValue === '' ? null : $rawValue;
    }

    private static function coerceDate(string $rawValue): ?string
    {
        if (preg_match('/^(?<year>\\d{4})-(?<month>\\d{2})$/', $rawValue, $monthMatch) === 1) {
            $year = (int) $monthMatch['year'];
            $month = (int) $monthMatch['month'];

            return checkdate($month, 1, $year) ? $rawValue : null;
        }

        if (preg_match('/^(?<year>\\d{4})-(?<month>\\d{2})-(?<day>\\d{2})$/', $rawValue, $dateMatch) === 1) {
            $year = (int) $dateMatch['year'];
            $month = (int) $dateMatch['month'];
            $day = (int) $dateMatch['day'];

            return checkdate($month, $day, $year) ? $rawValue : null;
        }

        return null;
    }
}
