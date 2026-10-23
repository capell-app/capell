<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use stdClass;

final class InstallReportRedactor
{
    /** @param array<array-key, mixed> $input
     * @return list<string>
     */
    public static function secrets(array $input): array
    {
        $secrets = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $secrets = [...$secrets, ...self::secrets($value)];
            } elseif (is_string($key) && preg_match('/password|secret|token|credential|api_key/i', $key) && is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }

        // Redact longer overlapping values first.
        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /** @param list<string> $secrets */
    public static function redact(mixed $value, array $secrets): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/password|secret|token|credential|api_key/i', $key)) {
                    continue;
                }

                $result[$key] = self::redact($item, $secrets);
            }

            return $result;
        }

        if (! is_string($value)) {
            return $value;
        }

        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $value = str_replace($secrets, '[redacted]', $value);
        $value = (string) preg_replace('/\b(password|secret|token|credential|api_key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value);

        return (string) preg_replace('~(://)[^/@\s]+:[^/@\s]+@~', '$1[redacted]@', $value);
    }
}
