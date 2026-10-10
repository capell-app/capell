<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;

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
        foreach ($groups as $group) {
            foreach (collect($group->getItems()) as $item) {
                $section = $this->sectionFor($item, $group);
                if ($section === null) {
                    $primary[] = $item;

                    continue;
                }

                $this->sections[$section][] = $item;
            }
        }

        foreach (['pages', 'articles', 'library', 'design', 'publishing', 'marketing', 'reports', 'system'] as $section) {
            $items = $this->sections[$section] ?? [];
            if ($items === []) {
                continue;
            }

            $items = $this->prioritise($section, $items);
            $this->sections[$section] = $items;
            $first = $items[0];
            $primary[] = NavigationItem::make(__('capell-admin::navigation.workspace_' . $section))
                ->key('capell.workspace.' . $section)
                ->icon(match ($section) {
                    'pages' => Heroicon::OutlinedGlobeAlt,
                    'articles' => Heroicon::OutlinedNewspaper,
                    'library' => Heroicon::OutlinedPhoto,
                    'design' => Heroicon::OutlinedSwatch,
                    'publishing' => Heroicon::OutlinedClipboardDocumentCheck,
                    'marketing' => Heroicon::OutlinedMegaphone,
                    'reports' => Heroicon::OutlinedChartBar,
                    default => Heroicon::OutlinedCog6Tooth,
                })
                ->url($first->getUrl())
                ->isActiveWhen(fn (): bool => $this->hasActiveItem($items));
        }

        return [NavigationGroup::make()->items($primary)];
    }

    /** @return array<NavigationGroup> */
    public function localNavigation(): array
    {
        foreach ($this->sections as $items) {
            if (! $this->hasActiveItem($items)) {
                continue;
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

        return [];
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

    /**
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function prioritise(string $section, array $items): array
    {
        $label = match ($section) {
            'pages' => 'pages',
            'articles' => null,
            'library' => 'media',
            'design' => 'layouts',
            'system' => 'settings',
            default => null,
        };
        if ($label !== null) {
            usort($items, fn (NavigationItem $a, NavigationItem $b): int => (int) ($b->getLabel() === __('capell-admin::navigation.' . $label))
                <=> (int) ($a->getLabel() === __('capell-admin::navigation.' . $label)));
        }

        return $items;
    }
}
