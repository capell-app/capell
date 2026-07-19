<?php

declare(strict_types=1);

namespace Capell\Core\Support\Json;

use JsonException;
use UnexpectedValueException;

final class JsonCodec
{
    /**
     * @throws JsonException
     */
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | $flags);
    }

    public static function encodeOrDefault(mixed $value, string $default = '', int $flags = 0): string
    {
        try {
            return self::encode($value, $flags);
        } catch (JsonException) {
            return $default;
        }
    }

    public static function encodeOrFalse(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }

    /** @throws JsonException */
    public static function decode(string $json, bool $associative = true): mixed
    {
        return json_decode($json, $associative, flags: JSON_THROW_ON_ERROR);
    }

    public static function decodeOrDefault(
        ?string $json,
        mixed $default = null,
        bool $associative = true,
    ): mixed {
        if ($json === null || $json === '') {
            return $default;
        }

        try {
            return self::decode($json, $associative);
        } catch (JsonException) {
            return $default;
        }
    }

    public static function decodeOrRaw(string $json): mixed
    {
        try {
            return self::decode($json);
        } catch (JsonException) {
            return $json;
        }
    }

    /**
     * @param  array<int|string, mixed>  $default
     * @return array<int|string, mixed>
     */
    public static function decodeArray(?string $json, array $default = []): array
    {
        $decoded = self::decodeOrDefault($json, $default);

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function decodeObject(?string $json, array $default = []): array
    {
        $decoded = self::decodeArray($json, $default);
        $object = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                return $default;
            }

            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws JsonException
     * @throws UnexpectedValueException
     */
    public static function decodeArrayOrFail(string $json): array
    {
        $decoded = self::decode($json);

        throw_unless(is_array($decoded), UnexpectedValueException::class, 'JSON value must decode to an array or object.');

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     * @throws UnexpectedValueException
     */
    public static function decodeObjectOrFail(string $json): array
    {
        $decoded = self::decodeArrayOrFail($json);
        $object = [];

        foreach ($decoded as $key => $value) {
            throw_unless(is_string($key), UnexpectedValueException::class, 'JSON value must decode to an object.');

            $object[$key] = $value;
        }

        return $object;
    }

    public static function decodeScalar(
        ?string $json,
        string|int|float|bool|null $default = null,
    ): string|int|float|bool|null {
        $decoded = self::decodeOrDefault($json, $default);

        return is_string($decoded) || is_int($decoded) || is_float($decoded) || is_bool($decoded) || $decoded === null
            ? $decoded
            : $default;
    }
}
