<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * A job a package declares it performs for Core, in the manifest `roles` list, so Core
 * can find the package by what it does instead of by its name.
 */
enum PackageRole: string
{
    case DefaultTheme = 'default-theme';
    case WorkflowFinal = 'workflow-final';
    case DeveloperTooling = 'developer-tooling';
    case SiteSocialFields = 'site-social-fields';
    case ScaffoldFullProfile = 'scaffold-full-profile';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases(),
        );
    }
}
