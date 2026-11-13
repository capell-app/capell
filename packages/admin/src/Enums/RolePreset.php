<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

/**
 * Ready-made roles an owner can give someone without judging a permission matrix.
 *
 * Each preset is seeded as an ordinary role, so an owner can still adjust it on
 * the Roles screen. Its permissions come from ResolveDefaultRolePermissionsAction,
 * which also powers "Reset to default".
 */
enum RolePreset: string
{
    case ClientEditor = 'client_editor';

    public static function forRoleName(string $roleName): ?self
    {
        return self::tryFrom($roleName);
    }

    public function roleName(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::ClientEditor => (string) __('capell-admin::role_presets.client_editor.label'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ClientEditor => (string) __('capell-admin::role_presets.client_editor.description'),
        };
    }

    /**
     * @return list<string>
     */
    public function canDo(): array
    {
        return match ($this) {
            self::ClientEditor => [
                (string) __('capell-admin::role_presets.client_editor.can.publish_pages'),
                (string) __('capell-admin::role_presets.client_editor.can.bin_pages'),
                (string) __('capell-admin::role_presets.client_editor.can.page_addresses'),
                (string) __('capell-admin::role_presets.client_editor.can.roll_back'),
                (string) __('capell-admin::role_presets.client_editor.can.media'),
                (string) __('capell-admin::role_presets.client_editor.can.menus'),
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function cannotDo(): array
    {
        return match ($this) {
            self::ClientEditor => [
                (string) __('capell-admin::role_presets.client_editor.cannot.design'),
                (string) __('capell-admin::role_presets.client_editor.cannot.settings'),
                (string) __('capell-admin::role_presets.client_editor.cannot.users'),
                (string) __('capell-admin::role_presets.client_editor.cannot.extensions'),
            ],
        };
    }
}
