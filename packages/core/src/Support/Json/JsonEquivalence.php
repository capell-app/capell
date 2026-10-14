<?php

declare(strict_types=1);

namespace Capell\Core\Support\Json;

use JsonException;
use stdClass;

final class JsonEquivalence
{
    public static function equivalent(string $original, string $submitted): bool
    {
        try {
            return self::equivalentValues(
                json_decode($original, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING),
                json_decode($submitted, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING),
            );
        } catch (JsonException) {
            return false;
        }
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

        // A decoded big integer is indistinguishable from a quoted numeric string.
        // Persist when that distinction is uncertain rather than discard a real edit.
        if (is_string($original) && preg_match('/^-?[0-9]+$/D', $original) === 1 && filter_var($original, FILTER_VALIDATE_INT) === false) {
            return false;
        }

        if (is_float($original) && ! is_finite($original)) {
            return false;
        }

        return $original === $submitted;
    }
}
