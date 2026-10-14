<?php

declare(strict_types=1);

namespace Capell\Core\Support\Json;

use JsonException;
use stdClass;

final class JsonEquivalence
{
    public static function equivalent(string $original, string $submitted): bool
    {
        if ($original === $submitted) {
            return true;
        }

        if (! json_validate($original) || ! json_validate($submitted)) {
            return false;
        }

        try {
            return self::equivalentValues(
                self::decodeForComparison($original),
                self::decodeForComparison($submitted),
            );
        } catch (JsonException) {
            return false;
        }
    }

    private static function decodeForComparison(string $json): mixed
    {
        // Tag every string and number, including keys, so quoted values cannot
        // impersonate numeric tokens and no number is converted to a PHP float.
        $normalised = preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
            fn (array $token): string => json_encode(
                str_starts_with($token[0], '"')
                    ? 'string:' . json_decode($token[0], flags: JSON_THROW_ON_ERROR)
                    : self::normaliseNumber($token[0]),
                JSON_THROW_ON_ERROR,
            ),
            $json,
        );

        return json_decode($normalised ?? '', flags: JSON_THROW_ON_ERROR);
    }

    private static function normaliseNumber(string $token): string
    {
        if (strpbrk($token, '.eE') === false) {
            return 'integer:' . ($token === '-0' ? '0' : $token);
        }

        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $token, $parts) !== 1) {
            return 'decimal:raw:' . $token;
        }

        $fraction = $parts[3] ?? '';
        $digits = ltrim($parts[2] . $fraction, '0');

        if ($digits === '') {
            return 'decimal:0';
        }

        $exponentText = $parts[4] ?? '0';
        $exponentDigits = ltrim(ltrim($exponentText, '+-'), '0');
        $exponentText = (str_starts_with($exponentText, '-') ? '-' : '') . ($exponentDigits === '' ? '0' : $exponentDigits);
        $exponent = filter_var($exponentText, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => PHP_INT_MIN + strlen($token),
                'max_range' => PHP_INT_MAX - strlen($token),
            ],
        ]);

        if ($exponent === false) {
            // Retain differing tokens when their exponent cannot be normalised safely.
            return 'decimal:raw:' . $token;
        }

        $coefficient = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($coefficient) - strlen($fraction);

        return 'decimal:' . $parts[1] . $coefficient . 'e' . $exponent;
    }

    private static function equivalentValues(mixed $original, mixed $submitted): bool
    {
        if ($original instanceof stdClass || $submitted instanceof stdClass) {
            if (! $original instanceof stdClass || ! $submitted instanceof stdClass) {
                return false;
            }

            $original = get_object_vars($original);
            $submitted = get_object_vars($submitted);
        }

        if (is_array($original) && is_array($submitted)) {
            if (count($original) !== count($submitted)) {
                return false;
            }

            return array_all($original, fn (mixed $value, int|string $key): bool => array_key_exists($key, $submitted) && self::equivalentValues($value, $submitted[$key]));
        }

        return $original === $submitted;
    }
}
