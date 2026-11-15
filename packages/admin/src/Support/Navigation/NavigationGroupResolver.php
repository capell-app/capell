<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Capell\Admin\Enums\NavigationGroupEnum;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Turns whatever a resource or page returns from getNavigationGroup() into a
 * NavigationGroupEnum case. Packages that still return a legacy translation
 * key or label are mapped to the nearest case and logged once per label, so
 * they keep working while they move to the enum.
 */
final class NavigationGroupResolver
{
    /** @var array<string, NavigationGroupEnum> */
    private const array LEGACY_KEYS = [
        'capell-admin::navigation.group_websites' => NavigationGroupEnum::Website,
        'capell-admin::navigation.group_content' => NavigationGroupEnum::Website,
        'capell-admin::navigation.group_workflow' => NavigationGroupEnum::Website,
        'capell-admin::navigation.group_layouts' => NavigationGroupEnum::Design,
        'capell-admin::navigation.group_growth' => NavigationGroupEnum::Marketing,
        'capell-admin::navigation.group_customers' => NavigationGroupEnum::Business,
        'capell-admin::navigation.group_operations' => NavigationGroupEnum::Business,
        'capell-admin::navigation.group_monitoring' => NavigationGroupEnum::Reports,
        'capell-admin::navigation.group_security' => NavigationGroupEnum::Settings,
        'capell-admin::navigation.group_system' => NavigationGroupEnum::Settings,
        'capell-admin::navigation.group_integrations' => NavigationGroupEnum::Settings,
        'capell-admin::navigation.group_extensions' => NavigationGroupEnum::Settings,
    ];

    /** @var array<string, true> */
    private array $reported = [];

    /**
     * Null means the item stays ungrouped at the top of the sidebar, which is
     * reserved for the dashboard. An unknown package label falls back to
     * Settings so the item stays reachable.
     */
    public function resolve(string|UnitEnum|null $group): ?NavigationGroupEnum
    {
        if ($group instanceof NavigationGroupEnum) {
            return $group;
        }

        if ($group instanceof UnitEnum) {
            $group = $group->name;
        }

        if ($group === null || $group === '' || $this->isDashboardGroup($group)) {
            return null;
        }

        $case = NavigationGroupEnum::tryFrom($group) ?? $this->caseForLabel($group);
        if ($case instanceof NavigationGroupEnum) {
            return $case;
        }

        $legacy = $this->legacyCaseFor($group);
        $this->reportLegacy($group, $legacy);

        return $legacy ?? NavigationGroupEnum::Settings;
    }

    private function caseForLabel(string $group): ?NavigationGroupEnum
    {
        foreach (NavigationGroupEnum::cases() as $case) {
            if ($case->translationKey() === $group || $case->getLabel() === $group) {
                return $case;
            }
        }

        return null;
    }

    private function legacyCaseFor(string $group): ?NavigationGroupEnum
    {
        if (array_key_exists($group, self::LEGACY_KEYS)) {
            return self::LEGACY_KEYS[$group];
        }

        return $this->legacyLabels()[$group] ?? null;
    }

    /**
     * Translated legacy labels, written as literal keys so the translation
     * drift check sees them in use while packages still return them.
     *
     * @return array<string, NavigationGroupEnum>
     */
    private function legacyLabels(): array
    {
        return [
            (string) __('capell-admin::navigation.group_websites') => NavigationGroupEnum::Website,
            (string) __('capell-admin::navigation.group_content') => NavigationGroupEnum::Website,
            (string) __('capell-admin::navigation.group_workflow') => NavigationGroupEnum::Website,
            (string) __('capell-admin::navigation.group_layouts') => NavigationGroupEnum::Design,
            (string) __('capell-admin::navigation.group_growth') => NavigationGroupEnum::Marketing,
            (string) __('capell-admin::navigation.group_customers') => NavigationGroupEnum::Business,
            (string) __('capell-admin::navigation.group_operations') => NavigationGroupEnum::Business,
            (string) __('capell-admin::navigation.group_monitoring') => NavigationGroupEnum::Reports,
            (string) __('capell-admin::navigation.group_security') => NavigationGroupEnum::Settings,
            (string) __('capell-admin::navigation.group_system') => NavigationGroupEnum::Settings,
            (string) __('capell-admin::navigation.group_integrations') => NavigationGroupEnum::Settings,
            (string) __('capell-admin::navigation.group_extensions') => NavigationGroupEnum::Settings,
        ];
    }

    private function isDashboardGroup(string $group): bool
    {
        return in_array($group, ['capell-admin::navigation.group_dashboard', __('capell-admin::navigation.group_dashboard')], true);
    }

    private function reportLegacy(string $group, ?NavigationGroupEnum $case): void
    {
        if (isset($this->reported[$group])) {
            return;
        }

        $this->reported[$group] = true;
        Log::debug('Deprecated: navigation group label is not a NavigationGroupEnum case.', [
            'group' => $group,
            'mapped_to' => ($case ?? NavigationGroupEnum::Settings)->value,
        ]);
    }
}
