<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

final class MarketplaceComposerCommand
{
    public static function require(string $composerName, ?string $versionConstraint = null): string
    {
        $requirement = $versionConstraint !== null && $versionConstraint !== ''
            ? $composerName . ':' . $versionConstraint
            : $composerName;

        // Quote the whole argument: valid Composer branches and ranges can
        // contain shell operators, quotes, whitespace and glob characters.
        return 'composer require ' . escapeshellarg($requirement);
    }

    public static function remove(string $composerName): string
    {
        return 'composer remove ' . escapeshellarg($composerName);
    }

    public static function storedRequire(string $composerName, ?string $versionConstraint, string $command): string
    {
        if ($versionConstraint === null || $versionConstraint === '') {
            $argument = substr($command, strlen('composer require '));

            // Accept both historical unquoted commands and this formatter's
            // quoted form when the optional constraint column was not stored.
            if (str_starts_with($argument, "'") && str_ends_with($argument, "'")) {
                $decoded = str_replace("'\\''", "'", substr($argument, 1, -1));

                if (escapeshellarg($decoded) === $argument) {
                    $argument = $decoded;
                }
            }

            $prefix = $composerName . ':';

            if (str_starts_with($command, 'composer require ') && str_starts_with($argument, $prefix)) {
                $versionConstraint = substr($argument, strlen($prefix));
            }
        }

        return self::require($composerName, $versionConstraint);
    }
}
