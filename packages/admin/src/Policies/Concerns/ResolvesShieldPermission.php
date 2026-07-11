<?php

declare(strict_types=1);

namespace Capell\Admin\Policies\Concerns;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;

/**
 * Builds Spatie permission names that match the host app's Filament Shield
 * configuration.
 *
 * Filament Shield v4 generates permissions using a configurable case and
 * separator (default: PascalCase + ':' → "Update:Page"). Earlier Shield
 * versions used snake_case + '_' → "update_page". Policies must NOT hardcode
 * either format: the host app controls `config/filament-shield.php`, and
 * hardcoded strings silently fail when they don't match.
 *
 * `hasPermissionTo()` throws `PermissionDoesNotExist` on an unknown permission,
 * which the Gate layer catches and treats as denial — so the failure surfaces
 * as "you don't have permission" with no clue the permission name is wrong.
 * Use this helper instead.
 */
trait ResolvesShieldPermission
{
    /**
     * Build a Shield-formatted permission key for the given affix + subject.
     * Example: `permission('update', 'Page')` → "Update:Page" (default config)
     * or "update_page" (legacy snake config).
     */
    protected static function permission(string $affix, string $subject): string
    {
        $permissions = Utils::getConfig()->permissions;

        return FilamentShield::defaultPermissionKeyBuilder(
            affix: $affix,
            separator: $permissions->separator,
            subject: $subject,
            case: $permissions->case,
        );
    }
}
