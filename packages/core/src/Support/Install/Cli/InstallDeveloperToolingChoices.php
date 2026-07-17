<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

final class InstallDeveloperToolingChoices
{
    /** @return array{label: string, default: bool, hint: string} */
    public static function installationPrompt(): array
    {
        return [
            'label' => 'Install AI / Agent Bridge developer tooling?',
            'default' => false,
            'hint' => 'Installs Laravel Boost and Capell Agent Bridge for local agent workflows.',
        ];
    }

    /** @return array{label: string, default: bool, hint: string} */
    public static function boostInstallationPrompt(): array
    {
        return [
            'label' => 'Run Laravel Boost installer for Agent Bridge, guidelines, and skills?',
            'default' => true,
            'hint' => 'Runs boost:install --guidelines --skills --mcp without interaction.',
        ];
    }

    /** @return array{0: true, 1: bool} */
    public static function explicitlyRequested(bool $skipBoostInstallation): array
    {
        return [true, ! $skipBoostInstallation];
    }

    /** @return array{0: true, 1: false} */
    public static function alreadyInstalled(): array
    {
        return [true, false];
    }

    /** @return array{0: false, 1: false} */
    public static function notInstalled(): array
    {
        return [false, false];
    }
}
