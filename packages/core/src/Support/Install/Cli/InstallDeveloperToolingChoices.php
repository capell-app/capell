<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Data\Install\DeveloperToolingChoiceData;

final class InstallDeveloperToolingChoices
{
    /** @return array{label: string, default: bool, hint: string} */
    public static function installationPrompt(): array
    {
        return [
            'label' => __('capell-core::install.developer_tooling.installation_label'),
            'default' => false,
            'hint' => __('capell-core::install.developer_tooling.installation_hint'),
        ];
    }

    /** @return array{label: string, default: bool, hint: string} */
    public static function boostInstallationPrompt(): array
    {
        return [
            'label' => __('capell-core::install.developer_tooling.boost_installation_label'),
            'default' => true,
            'hint' => __('capell-core::install.developer_tooling.boost_installation_hint'),
        ];
    }

    public static function explicitlyRequested(bool $skipBoostInstallation): DeveloperToolingChoiceData
    {
        return new DeveloperToolingChoiceData(
            installDeveloperTooling: true,
            configureBoostDeveloperTooling: ! $skipBoostInstallation,
        );
    }

    public static function alreadyInstalled(): DeveloperToolingChoiceData
    {
        return new DeveloperToolingChoiceData(
            installDeveloperTooling: true,
            configureBoostDeveloperTooling: false,
        );
    }

    public static function notInstalled(): DeveloperToolingChoiceData
    {
        return new DeveloperToolingChoiceData(
            installDeveloperTooling: false,
            configureBoostDeveloperTooling: false,
        );
    }
}
