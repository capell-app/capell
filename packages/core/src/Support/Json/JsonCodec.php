<?php

declare(strict_types=1);

namespace Capell\Core\Support\Json;

use JsonException;

final class JsonCodec
{
    /**
     * @param  array<int|string, mixed>  $value
     *
     * @throws JsonException
     */
    public static function encode(array $value, int $flags = 0): string
    {
        return self::encodeValue($value, $flags);
    }

    /**
     * @throws JsonException
     */
    public static function encodeValue(mixed $value, int $flags = 0): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | $flags);
    }

    /**
     * @param  array<int|string, mixed>  $default
     * @return array<int|string, mixed>
     */
    public static function decodeArray(?string $json, array $default = []): array
    {
        if ($json === null || $json === '') {
            return $default;
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }

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
}
