<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

trait HasMigrations
{
    /**
     * @return array<int, string>
     */
    public static function getMigrations(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public static function getSettingMigrations(): array
    {
        return [
            '2026_05_10_190834_01_add_admin_settings',
            '2026_05_28_000001_01_add_header_navigation_tree_admin_setting',
            '2026_06_01_000001_01_add_configurator_path_hint_admin_setting',
            '2026_06_05_000001_01_add_report_visibility_admin_setting',
        ];
    }
}
