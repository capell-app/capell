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
        // The scan avoids regular expressions so string length is never bounded
        // by PCRE backtracking or JIT stack limits. Input is already validated.
        $length = strlen($json);
        $normalised = '';
        $offset = 0;

        while ($offset < $length) {
            $structural = strcspn($json, '"-0123456789', $offset);
            $normalised .= substr($json, $offset, $structural);
            $offset += $structural;

            if ($offset >= $length) {
                break;
            }

            if ($json[$offset] === '"') {
                $end = self::stringTokenEnd($json, $offset + 1);
                $decoded = json_decode(substr($json, $offset, $end - $offset), flags: JSON_THROW_ON_ERROR);

                throw_unless(is_string($decoded), JsonException::class, 'Unexpected string token.');

                $normalised .= json_encode('string:' . $decoded, JSON_THROW_ON_ERROR);
                $offset = $end;

                continue;
            }

            $numberLength = strspn($json, '-+.eE0123456789', $offset);
            $normalised .= json_encode(self::normaliseNumber(substr($json, $offset, $numberLength)), JSON_THROW_ON_ERROR);
            $offset += $numberLength;
        }

        return json_decode($normalised, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Return the offset just past the closing quote of the string token whose
     * content starts at $offset.
     */
    private static function stringTokenEnd(string $json, int $offset): int
    {
        $start = $offset;

        while (($quote = strpos($json, '"', $offset)) !== false) {
            // A quote preceded by an odd run of backslashes is escaped.
            $backslashes = 0;

            while ($quote - $backslashes > $start && $json[$quote - $backslashes - 1] === '\\') {
                $backslashes++;
            }

            if ($backslashes % 2 === 0) {
                return $quote + 1;
            }

            $offset = $quote + 1;
        }

        throw new JsonException('Unterminated string token.');
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
