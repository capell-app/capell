<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Data\Security\SecurityPostureInventoryData;

final class SecurityEvidence
{
    /** @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function environment(SecurityPostureInventoryData $inventory, array $keys): array
    {
        $evidence = [];
        foreach ($keys as $key) {
            $value = $inventory->flag($key);
            $evidence[$key] = match (true) {
                $value === true => 'true',
                $value === false => 'false',
                $value === null => 'missing',
                $value === 'production' => 'production',
                $value === 'https' => 'https',
                is_string($value) => 'other string',
                default => get_debug_type($value),
            };
        }

        // Key names are values: the output sanitiser redacts sensitive-looking keys.
        return ['observations' => array_map(static fn (string $key, string $value): array => ['setting' => $key, 'class' => $value], array_keys($evidence), array_values($evidence))];
    }

    /** @param array<array-key, mixed> $evidence */
    public static function complete(array $evidence): bool
    {
        return array_all($evidence, static fn (mixed $value, int|string $key): bool => ! (
            (is_string($key) && str_starts_with($key, '[redacted-key-'))
            || $key === '[truncated]'
            || ($key === 'truncated' && $value === true)
            || in_array($value, ['[truncated]', '[redacted]'], true)
            || (is_array($value) && ! self::complete($value))
        ));
    }

    /** @param array<array-key, mixed> $evidence */
    public static function hash(array $evidence): string
    {
        return hash('sha256', json_encode(self::canonicalise($evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonicalise(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        return $value;
    }
}
