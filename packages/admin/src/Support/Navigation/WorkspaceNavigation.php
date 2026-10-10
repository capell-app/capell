<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/** Keeps installed package navigation intact while moving secondary tools out of the sidebar. */
final class WorkspaceNavigation
{
    /** @var array<string, list<NavigationItem>> */
    private array $sections = [];

    /**
     * @param  array<NavigationGroup>  $groups
     * @return array<NavigationGroup>
     */
    public function organise(array $groups): array
    {
        $this->sections = [];
        $primary = [];
        $additional = [];
        foreach ($groups as $group) {
            foreach (collect($group->getItems()) as $item) {
                $section = $this->sectionFor($item, $group);
                if ($section !== null) {
                    $this->sections[$section][] = $item;

                    continue;
                }

                if ($group->getLabel() === null) {
                    $primary[] = $item;

                    continue;
                }

                $label = $group->getLabel();
                $additional[$label] ??= (clone $group)->items([]);
                $additional[$label]->items([...collect($additional[$label]->getItems())->all(), $item]);
            }
        }

        $result = $primary === [] ? [] : [NavigationGroup::make()->items($primary)];
        $website = [...($this->sections['pages'] ?? []), ...($this->sections['articles'] ?? [])];
        usort($website, fn (NavigationItem $a, NavigationItem $b): int => $a->getSort() <=> $b->getSort());
        $website = $this->nestParentItems($website);
        foreach ([
            'pages' => $website,
            'library' => $this->sections['library'] ?? [],
            'design' => $this->designRoots(),
            'publishing' => $this->sections['publishing'] ?? [],
            'marketing' => $this->sections['marketing'] ?? [],
            'reports' => $this->sections['reports'] ?? [],
            'system' => $this->sections['system'] ?? [],
        ] as $section => $items) {
            if ($items === []) {
                continue;
            }

            $label = $section === 'pages'
                ? __('capell-admin::navigation.group_websites')
                : __('capell-admin::navigation.workspace_' . $section);
            $result[] = NavigationGroup::make($label)->items($items)
                ->collapsed(! in_array($section, ['pages', 'library'], true));
        }

        return [...$result, ...array_values($additional)];
    }

    /** @return array<NavigationGroup> */
    public function localNavigation(): array
    {
        $items = $this->sections['design'] ?? [];
        if (! $this->hasActiveItem($items)) {
            return [];
        }

        $navigation = [];
        foreach ($items as $item) {
            $navigation[] = (clone $item)->childItems([]);
            foreach (collect($item->getChildItems()) as $child) {
                if ($child->isVisible()) {
                    $navigation[] = $child;
                }
            }
        }

        return [NavigationGroup::make()->items($navigation)];
    }

    /** @return list<NavigationItem> */
    private function designRoots(): array
    {
        $items = $this->sections['design'] ?? [];
        usort($items, fn (NavigationItem $a, NavigationItem $b): int => (int) ($b->getLabel() === __('capell-admin::navigation.layouts')) <=> (int) ($a->getLabel() === __('capell-admin::navigation.layouts')));
        $this->sections['design'] = $items;
        $roots = array_values(array_filter($items, function (NavigationItem $item): bool {
            $path = parse_url($item->getUrl() ?? '', PHP_URL_PATH);

            return ! is_string($path) || ! array_any(
                ['layout-builder/presets', 'layout-builder/widgets', 'block-templates', 'blueprints'],
                fn (string $suffix): bool => str_ends_with($path, '/' . $suffix),
            );
        }));
        if ($roots === [] && $items !== []) {
            $roots = [$items[0]];
        }

        if ($roots !== []) {
            $first = clone $roots[0];
            $otherKeys = array_map(fn (NavigationItem $item): string => $item->getKey(), array_slice($roots, 1));
            $first->isActiveWhen(fn (): bool => $this->hasActiveItem(array_values(array_filter(
                $items,
                fn (NavigationItem $item): bool => ! in_array($item->getKey(), $otherKeys, true),
            ))));
            $roots[0] = $first;
        }

        return $roots;
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

    private function sectionFor(NavigationItem $item, NavigationGroup $group): ?string
    {
        $path = parse_url($item->getUrl() ?? '', PHP_URL_PATH);
        $path = is_string($path) ? trim($path, '/') : '';
        foreach ([
            'pages' => ['pages', 'navigation/navigations', 'redirects', 'redirect-rules', 'sitemap'],
            'articles' => ['blog/article', 'tags', 'tags/tags', 'categories'],
            'library' => ['media', 'media-health', 'content-sections/sections'],
            'design' => ['layouts', 'layout-builder/layouts', 'layout-builder/widgets', 'layout-builder/presets', 'block-templates', 'themes', 'blueprints'],
            'system' => ['activities', 'users', 'shield/roles', 'sites', 'languages', 'settings', 'extensions', 'upgrade', 'site-health', 'maintenance-cache', 'html-cache'],
        ] as $section => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (str_ends_with($path, '/' . $suffix)) {
                    return $section;
                }
            }
        }

        foreach ([
            'publishing' => ['group_workflow'],
            'marketing' => ['group_marketing', 'group_growth'],
            'reports' => ['group_reports'],
            'system' => ['group_system', 'group_settings', 'group_monitoring', 'group_integrations', 'group_extensions'],
            'library' => ['group_content'],
            'design' => ['group_layouts'],
        ] as $section => $keys) {
            foreach ($keys as $key) {
                if ($group->getLabel() === __('capell-admin::navigation.' . $key)) {
                    return $section;
                }
            }
        }

        return null;
    }

    /** @param list<NavigationItem> $items */
    private function hasActiveItem(array $items): bool
    {
        return array_any($items, fn (NavigationItem $item): bool => $item->isActive() || $item->isChildItemsActive());
    }
}
