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

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $preset) {
            $options[$preset->value] = $preset->label();
        }

        return $options;
    }

    public function roleName(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return (string) __('capell-admin::role_presets.' . $this->value . '.label');
    }

    public function description(): string
    {
        return (string) __('capell-admin::role_presets.' . $this->value . '.description');
    }

    /**
     * @return list<string>
     */
    public function canDo(): array
    {
        return $this->lines('can');
    }

    /**
     * @return list<string>
     */
    public function cannotDo(): array
    {
        return $this->lines('cannot');
    }

    /**
     * @return list<string>
     */
    private function lines(string $kind): array
    {
        $lines = __('capell-admin::role_presets.' . $this->value . '.' . $kind);

        return is_array($lines) ? array_values(array_map(strval(...), $lines)) : [];
    }
}
