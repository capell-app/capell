<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

/**
 * Answers the one question every "can this host shell out?" caller asks.
 *
 * Shared hosts routinely leave proc_open defined but list it in
 * disable_functions, so `function_exists` alone is not enough. The doctor check
 * and the Marketplace installer must agree about the same host, so the probe
 * lives here rather than being copied into each of them.
 */
final class ProcessExecutionSupport
{
    public static function isAvailable(): bool
    {
        return function_exists('proc_open') && ! self::isDisabled('proc_open');
    }

    public static function isDisabled(string $function): bool
    {
        return in_array(
            strtolower($function),
            self::disabledFunctionsFrom((string) ini_get('disable_functions')),
            true,
        );
    }

    /**
     * @return list<string>
     */
    public static function disabledFunctionsFrom(string $disableFunctions): array
    {
        if ($disableFunctions === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $function): string => strtolower(trim($function)),
                explode(',', $disableFunctions),
            ),
            static fn (string $function): bool => $function !== '',
        ));
    }
}
