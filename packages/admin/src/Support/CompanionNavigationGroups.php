<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

/**
 * Navigation group labels that only companion packages register. Admin ships
 * their translations, so each shipped locale must define them even when no
 * companion that uses them is installed.
 */
final class CompanionNavigationGroups
{
    /** @var list<string> */
    public const array LABELS = [
        'capell-admin::navigation.group_extensions',
        'capell-admin::navigation.group_growth',
        'capell-admin::navigation.group_integrations',
    ];

    /**
     * Every companion group label, translated for the current locale.
     *
     * @return array<string, string>
     */
    public static function translated(): array
    {
        return [
            'capell-admin::navigation.group_extensions' => __('capell-admin::navigation.group_extensions'),
            'capell-admin::navigation.group_growth' => __('capell-admin::navigation.group_growth'),
            'capell-admin::navigation.group_integrations' => __('capell-admin::navigation.group_integrations'),
        ];
    }
}
