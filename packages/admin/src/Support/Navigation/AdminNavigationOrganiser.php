<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\AnalyticsDashboard;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Pages\DashboardToolsPage;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Filament\Pages\SecurityPage;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Pages\SiteAdminMetricsPage;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Filament\Pages\UpgradePage;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * Builds the sidebar from the fixed NavigationGroupEnum set: the dashboard
 * first and ungrouped, then each group in enum order. Core items keep a fixed
 * order and package items follow alphabetically, so package sort numbers can
 * no longer reshuffle a group.
 */
final class AdminNavigationOrganiser
{
    /** @var list<class-string> */
    public const array CORE_ORDER = [
        CapellDashboard::class,
        PageResource::class,
        RedirectResource::class,
        MediaResource::class,
        LayoutResource::class,
        ThemeResource::class,
        BlockTemplateResource::class,
        BlueprintResource::class,
        AnalyticsDashboard::class,
        SiteAdminMetricsPage::class,
        DashboardToolsPage::class,
        UserResource::class,
        RoleResource::class,
        ActivityResource::class,
        SettingsPage::class,
        SiteResource::class,
        LanguageResource::class,
        SiteHealthPage::class,
        SecurityPage::class,
        ExtensionsPage::class,
        UpgradePage::class,
    ];

    public function __construct(
        private readonly NavigationGroupResolver $resolver,
    ) {}

    /**
     * @param  array<NavigationGroup>  $groups
     * @return list<NavigationGroup>
     */
    public function organise(array $groups): array
    {
        $ungrouped = [];
        $grouped = [];
        $entries = [];
        $parentCases = [];

        foreach ($groups as $group) {
            foreach (collect($group->getItems()) as $item) {
                $case = $this->resolver->resolve($item->getGroup() ?? $group->getLabel());
                $entries[] = [$item, $case];
                if ($item->getParentItem() === null) {
                    $parentCases[$item->getKey()] = $case;
                    $parentCases[(string) $item->getLabel()] = $case;
                }
            }
        }

        foreach ($entries as [$item, $case]) {
            // A child with no group of its own follows its parent, so Filament
            // dropping it from a different group cannot hide it.
            $parentKey = $item->getParentItem();
            if ($parentKey !== null && $item->getGroup() === null && array_key_exists($parentKey, $parentCases)) {
                $case = $parentCases[$parentKey];
            }

            if (! $case instanceof NavigationGroupEnum) {
                $ungrouped[] = $item;

                continue;
            }

            $grouped[$case->value][] = $item;
        }

        $replaced = $this->replacedKeys([...$ungrouped, ...array_merge([], ...array_values($grouped))]);
        $result = [];

        $ungrouped = $this->prepare($ungrouped, $replaced);
        if ($ungrouped !== []) {
            $result[] = NavigationGroup::make()->items($ungrouped);
        }

        foreach (NavigationGroupEnum::cases() as $case) {
            $items = $this->prepare($grouped[$case->value] ?? [], $replaced);
            if ($items === []) {
                continue;
            }

            $result[] = NavigationGroup::make($case->getLabel())
                ->items($items)
                ->collapsed($case->isCollapsedByDefault());
        }

        return $result;
    }

    /**
     * @param  list<NavigationItem>  $items
     * @param  array<string, true>  $replaced
     * @return list<NavigationItem>
     */
    private function prepare(array $items, array $replaced): array
    {
        $items = array_values(array_filter(
            $items,
            fn (NavigationItem $item): bool => ! isset($replaced[$item->getKey()]),
        ));

        usort($items, $this->compare(...));

        return $this->nestParentItems($items);
    }

    /**
     * Keys of core items whose replacement is present in the navigation.
     *
     * @param  list<NavigationItem>  $items
     * @return array<string, true>
     */
    private function replacedKeys(array $items): array
    {
        $present = [];
        foreach ($items as $item) {
            $present[$item->getKey()] = true;
        }

        $replaced = [];
        foreach (CapellAdmin::getNavigationReplacements() as $replacedKey => $replacementKey) {
            if (isset($present[$replacementKey])) {
                $replaced[$replacedKey] = true;
            }
        }

        return $replaced;
    }

    private function compare(NavigationItem $first, NavigationItem $second): int
    {
        $firstPosition = $this->corePosition($first);
        $secondPosition = $this->corePosition($second);

        if ($firstPosition !== $secondPosition) {
            return $firstPosition <=> $secondPosition;
        }

        return strnatcasecmp($first->getLabel(), $second->getLabel());
    }

    private function corePosition(NavigationItem $item): int
    {
        $position = array_search($this->replacedCoreKey($item->getKey()), self::CORE_ORDER, true);

        return $position === false ? PHP_INT_MAX : $position;
    }

    /** A replacement item takes the slot of the core item it replaces. */
    private function replacedCoreKey(string $key): string
    {
        $replacedKey = array_search($key, CapellAdmin::getNavigationReplacements(), true);

        return is_string($replacedKey) ? $replacedKey : $key;
    }

    /**
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function nestParentItems(array $items): array
    {
        $parents = [];
        foreach ($items as $item) {
            if ($item->getParentItem() === null) {
                $parents[$item->getKey()] = clone $item;
            }
        }

        foreach ($items as $item) {
            $parentKey = $item->getParentItem();
            if ($parentKey === null) {
                continue;
            }

            $parent = $parents[$parentKey] ?? collect($parents)->first(fn (NavigationItem $candidate): bool => $candidate->getLabel() === $parentKey);
            if ($parent instanceof NavigationItem) {
                $parent->childItems([...collect($parent->getChildItems())->all(), clone $item]);
            } else {
                $parents[$item->getKey()] = clone $item;
            }
        }

        return array_values($parents);
    }
}
