<?php

declare(strict_types=1);

namespace Capell\Core\Support\Composer;

final class InstallComposerFailure
{
    public static function explanation(string $output): string
    {
        $key = match (true) {
            preg_match('/(?:licen[cs]e|entitlement).*(?:expired|revoked|does not cover|not authori[sz]ed|denied)/i', $output) === 1 => 'licence',
            preg_match('/\b(?:http(?:\/\d(?:\.\d)?)?|status(?: code)?|error)\s*[:=]?\s*(?:401|403)\b|\b(?:401|403)\s+(?:unauthorized|forbidden)\b|authentication required|could not authenticate|invalid credentials|username.*(?:required|interactive)|authorization failed/i', $output) === 1 => 'access',
            preg_match('/could not resolve host|connection timed out|curl error (?:6|7|28)|network is unreachable/i', $output) === 1 => 'network',
            preg_match('/could not be resolved|could not find a matching version|minimum-stability|conflicts? with|requires .*but/i', $output) === 1 => 'compatibility',
            preg_match('/could not be found|was not found|package.*not found/i', $output) === 1 => 'repository',
            default => 'unknown',
        };

        return (string) match ($key) {
            'licence' => __('capell-core::install.composer_failure.licence'),
            'access' => __('capell-core::install.composer_failure.access'),
            'network' => __('capell-core::install.composer_failure.network'),
            'compatibility' => __('capell-core::install.composer_failure.compatibility'),
            'repository' => __('capell-core::install.composer_failure.repository'),
            default => __('capell-core::install.composer_failure.unknown'),
        };
    }
}
