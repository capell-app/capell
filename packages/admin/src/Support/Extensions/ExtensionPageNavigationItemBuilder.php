<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use BackedEnum;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Facades\CapellCore;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;

use function Filament\Support\original_request;

use Illuminate\Contracts\Support\Arrayable;
use Throwable;

final class ExtensionPageNavigationItemBuilder
{
    /**
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        $items = [];

        foreach ($this->groupedItems() as $navigationGroup) {
            $groupItems = $navigationGroup->getItems();
            $groupItems = $groupItems instanceof Arrayable ? $groupItems->toArray() : $groupItems;

            foreach ($groupItems as $item) {
                if ($item instanceof NavigationItem) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  class-string<Page>  $page
     * @return list<NavigationItem>
     */
    public function siblingItemsForPage(string $page): array
    {
        $registry = resolve(ExtensionPageRegistry::class);
        $packageName = $registry->packageNameForPage($page);

        if ($packageName === null) {
            return [];
        }

        $pages = collect($registry->pagesForPackage($packageName))
            ->unique()
            ->filter(fn (string $registeredPage): bool => $this->pageIsAccessible($registeredPage))
            ->sort(fn (string $firstPage, string $secondPage): int => [
                $firstPage::getNavigationSort() ?? PHP_INT_MAX,
                mb_strtolower($firstPage::getNavigationLabel()),
            ] <=> [
                $secondPage::getNavigationSort() ?? PHP_INT_MAX,
                mb_strtolower($secondPage::getNavigationLabel()),
            ])
            ->values();
        $surfaceItems = $this->surfaceItemsForPackage($packageName, $pages->count());

        if ($pages->count() + count($surfaceItems) < 2) {
            return [];
        }

        $items = $pages
            ->map(fn (string $registeredPage, int $index): ?NavigationItem => $this->item($registeredPage, $index))
            ->filter()
            ->concat($surfaceItems)
            ->values()
            ->all();

        return array_values($items);
    }

    /**
     * @return list<NavigationGroup>
     */
    public function groupedItems(): array
    {
        /** @var array<class-string<Page>, array{group: string, page: class-string<Page>}> $records */
        $records = [];

        foreach (resolve(ExtensionPageRegistry::class)->entries() as $entry) {
            $page = $entry['page'];
            if (isset($records[$page])) {
                continue;
            }

            if (! $this->pageIsAccessible($page)) {
                continue;
            }

            $records[$page] = [
                'group' => $this->groupForPackage($entry['packageName']),
                'page' => $page,
            ];
        }

        uasort($records, $this->compareRecords(...));

        $groups = [];
        $index = 0;

        foreach ($records as $record) {
            $item = $this->item($record['page'], $index);

            if (! $item instanceof NavigationItem) {
                continue;
            }

            $groups[$record['group']][] = $item;
            $index++;
        }

        foreach (resolve(ExtensionManagementSurfaceRegistry::class)->packageNames() as $packageName) {
            foreach ($this->surfaceItemsForPackage($packageName, $index) as $surfaceItem) {
                $groups[$this->groupForPackage($packageName)][] = $surfaceItem;
                $index++;
            }
        }

        $navigationGroups = [];

        foreach ($groups as $label => $items) {
            $navigationGroups[] = NavigationGroup::make()
                ->label($label)
                ->items($items);
        }

        return $navigationGroups;
    }

    /**
     * @param  array{group: string, page: class-string<Page>}  $firstRecord
     * @param  array{group: string, page: class-string<Page>}  $secondRecord
     */
    private function compareRecords(array $firstRecord, array $secondRecord): int
    {
        return [
            mb_strtolower($firstRecord['group']),
            mb_strtolower($firstRecord['page']::getNavigationLabel()),
        ] <=> [
            mb_strtolower($secondRecord['group']),
            mb_strtolower($secondRecord['page']::getNavigationLabel()),
        ];
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function item(string $page, int $index): ?NavigationItem
    {
        $url = $this->pageUrl($page);

        if ($url === null) {
            return null;
        }

        return NavigationItem::make($page::getNavigationLabel())
            ->icon($this->pageIcon($page))
            ->activeIcon($this->pageActiveIcon($page))
            ->isActiveWhen(fn (): bool => original_request()->routeIs($page::getNavigationItemActiveRoutePattern()))
            ->sort($page::getNavigationSort() ?? $index)
            ->badge($page::getNavigationBadge(), color: $page::getNavigationBadgeColor())
            ->badgeTooltip($page::getNavigationBadgeTooltip())
            ->url($url);
    }

    /**
     * @return list<NavigationItem>
     */
    private function surfaceItemsForPackage(string $packageName, int $startIndex = 0): array
    {
        $items = collect(resolve(ExtensionManagementSurfaceRegistry::class)->surfacesForPackage($packageName))
            ->filter(fn (ExtensionManagementSurfaceData $surface): bool => $surface->type === 'settings'
                && is_string($surface->settingsGroup)
                && $surface->settingsGroup !== ''
                && ExtensionsPage::canManageExtensions())
            ->values()
            ->map(fn (ExtensionManagementSurfaceData $surface, int $index): NavigationItem => NavigationItem::make($this->translationString($surface->label))
                ->icon($surface->icon)
                ->isActiveWhen(fn (): bool => original_request()->fullUrlIs($this->surfaceUrl($surface)))
                ->sort($startIndex + $index)
                ->url($this->surfaceUrl($surface)))
            ->all();

        return array_values($items);
    }

    private function translationString(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }

    private function surfaceUrl(ExtensionManagementSurfaceData $surface): string
    {
        return ExtensionsPage::getUrl([
            'manage' => $surface->packageName,
            'surface' => $surface->settingsGroup,
        ]);
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageIsAccessible(string $page): bool
    {
        if (! is_subclass_of($page, Page::class)) {
            return false;
        }

        try {
            return $page::canAccess();
        } catch (Throwable) {
            return false;
        }
    }

    private function groupForPackage(string $packageName): string
    {
        if (! CapellCore::hasPackage($packageName)) {
            return (string) __('capell-admin::navigation.extension_type_uncategorised');
        }

        $group = trim(CapellCore::getPackage($packageName)->getProductGroup());

        return $group !== ''
            ? $group
            : (string) __('capell-admin::navigation.extension_type_uncategorised');
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageUrl(string $page): ?string
    {
        try {
            return $page::getNavigationUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageIcon(string $page): string|BackedEnum|null
    {
        try {
            $icon = $page::getNavigationIcon();
        } catch (Throwable) {
            return null;
        }

        return $icon instanceof BackedEnum || is_string($icon) ? $icon : null;
    }

    /**
     * @param  class-string<Page>  $page
     */
    private function pageActiveIcon(string $page): string|BackedEnum|null
    {
        try {
            $icon = $page::getActiveNavigationIcon();
        } catch (Throwable) {
            return null;
        }

        return $icon instanceof BackedEnum || is_string($icon) ? $icon : null;
    }
}
