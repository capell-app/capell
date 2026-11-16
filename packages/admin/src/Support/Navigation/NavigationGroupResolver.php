<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Capell\Admin\Enums\NavigationGroupEnum;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Turns whatever a resource or page returns from getNavigationGroup() into a
 * NavigationGroupEnum case: the case itself, its value, its translation key or
 * its label. Anything else is logged once and shown under Settings so the item
 * stays reachable.
 */
final class NavigationGroupResolver
{
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

        $this->reportUnknown($group);

        return NavigationGroupEnum::Settings;
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

    private function isDashboardGroup(string $group): bool
    {
        return in_array($group, ['capell-admin::navigation.group_dashboard', __('capell-admin::navigation.group_dashboard')], true);
    }

    private function reportUnknown(string $group): void
    {
        if (isset($this->reported[$group])) {
            return;
        }

        $this->reported[$group] = true;
        Log::debug('Navigation group is not a NavigationGroupEnum case; showing the item under Settings.', ['group' => $group]);
    }
}
