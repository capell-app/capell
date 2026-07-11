<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use BadMethodCallException;
use Capell\Admin\Settings\AdminSettings;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Provides canView() gating logic for any Filament widget base class.
 *
 * Consuming classes must declare:
 *   protected static array $rolesConfigKeys = [];
 *   protected static string $settingsKey = '';
 *
 * Widgets that override canView() with extra logic should call
 * static::canViewCheck() instead of parent::canView().
 */
trait GatedByRoleAndSettings
{
    public static function canViewCheck(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if (static::settingsKey() !== '') {
            try {
                $settings = resolve(AdminSettings::class);
                if (! $settings->isWidgetEnabled(static::settingsKey())) {
                    return false;
                }
            } catch (MissingSettings) {
                return false;
            }
        }

        // super_admin bypasses role gating.
        try {
            $superAdminRole = config('capell.roles.super_admin', 'super_admin');
            if (is_string($superAdminRole) && $superAdminRole !== '' && $user->hasRole($superAdminRole)) {
                return true;
            }
        } catch (BadMethodCallException) {
            // Role system not available — do not bypass.
        }

        $roleKeys = static::rolesConfigKeys();
        if ($roleKeys === []) {
            return true;
        }

        $roleNames = [];
        foreach ($roleKeys as $roleKey) {
            $name = config('capell.roles.' . $roleKey);
            if (is_string($name) && $name !== '') {
                $roleNames[] = $name;
            }
        }

        if ($roleNames === []) {
            return false;
        }

        try {
            return $user->hasAnyRole($roleNames);
        } catch (BadMethodCallException) {
            return false;
        }
    }

    public static function canView(): bool
    {
        return static::canViewCheck();
    }

    public static function settingsKey(): string
    {
        return static::$settingsKey;
    }

    /** @return list<string> */
    public static function rolesConfigKeys(): array
    {
        return array_values(static::$rolesConfigKeys);
    }
}
