<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Navigation;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Override;
use UnitEnum;

final class WorkspaceNavigationManager extends NavigationManager
{
    /** @return array<NavigationGroup> */
    #[Override]
    public function get(): array
    {
        $groups = parent::get();
        if (! $this->panel->hasPlugin(CapellAdminPlugin::ID) || $this->panel->hasNavigationBuilder()) {
            return $groups;
        }

        $registeredKeys = [];
        foreach ($groups as $group) {
            foreach (collect($group->getItems()) as $item) {
                $this->collectKeys($item, $registeredKeys);
            }
        }

        // Filament drops a child when its parent uses a different group. Package
        // resources with their own access permission must remain discoverable.
        foreach ($this->getNavigationItems() as $item) {
            if (isset($registeredKeys[$item->getKey() . ':' . $item->getUrl()])) {
                continue;
            }

            if (! $item->isVisible()) {
                continue;
            }

            if ($item->getUrl() === null) {
                continue;
            }

            $label = $item->getGroup();
            $groups[] = NavigationGroup::make($label instanceof UnitEnum ? $label->name : $label)
                ->items([clone $item]);
            $this->collectKeys($item, $registeredKeys);
        }

        return resolve(WorkspaceNavigation::class)->organise($groups);
    }

    /** @param array<string, true> $keys */
    private function collectKeys(NavigationItem $item, array &$keys): void
    {
        $keys[$item->getKey() . ':' . $item->getUrl()] = true;
        foreach (collect($item->getChildItems()) as $child) {
            $this->collectKeys($child, $keys);
        }
    }
}
