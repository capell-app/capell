<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use BackedEnum;
use Capell\Admin\Data\NavigationGroupData;
use Capell\Admin\Enums\NavigationGroupPositionEnum;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

trait HasNavigation
{
    /** @var array<int, NavigationGroup> */
    protected array $navigationGroups = [];

    /** @var array<string, NavigationGroupData> */
    protected array $registeredNavigationGroups = [];

    /**
     * @return array<int, NavigationGroup>
     */
    public function getDefaultNavigationGroups(): array
    {
        return array_map(
            fn (NavigationGroupData $navigationGroup): NavigationGroup => $this->makeNavigationGroup($navigationGroup),
            $this->defaultNavigationGroupData(),
        );
    }

    /**
     * @return array<int, NavigationGroup>
     */
    public function getNavigationGroups(): array
    {
        if ($this->navigationGroups !== []) {
            return $this->mergeRegisteredNavigationGroupsIntoCustomGroups($this->navigationGroups);
        }

        return array_map(
            fn (NavigationGroupData $navigationGroup): NavigationGroup => $this->makeNavigationGroup($navigationGroup),
            $this->orderedNavigationGroupData(),
        );
    }

    /**
     * @param  array<int, NavigationGroup>  $navigationGroups
     */
    public function setNavigationGroups(array $navigationGroups): static
    {
        $this->navigationGroups = $navigationGroups;

        return $this;
    }

    public function registerNavigationGroup(
        string $label,
        null|string|BackedEnum $icon = null,
        NavigationGroupPositionEnum $position = NavigationGroupPositionEnum::End,
        ?string $relativeTo = null,
        bool $collapsed = true,
    ): static {
        $navigationGroup = new NavigationGroupData(
            label: $label,
            icon: $icon,
            position: $position,
            relativeTo: $relativeTo,
            collapsed: $collapsed,
        );

        $key = $this->navigationGroupKey($label);
        $this->registeredNavigationGroups[$key] = isset($this->registeredNavigationGroups[$key])
            ? $this->registeredNavigationGroups[$key]->merge($navigationGroup)
            : $navigationGroup;

        return $this;
    }

    /**
     * @return array<int, NavigationItem>
     */
    public function getNavigationItems(): array
    {
        return [];
    }

    /**
     * @return list<NavigationGroupData>
     */
    private function defaultNavigationGroupData(): array
    {
        return [
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_dashboard',
                position: NavigationGroupPositionEnum::Start,
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_websites',
                collapsed: false,
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_content',
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_workflow',
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_layouts',
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_marketing',
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_reports',
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_monitoring',
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_settings',
                collapsed: false,
            ),
            new NavigationGroupData(
                label: 'capell-admin::navigation.group_system',
            ),
        ];
    }

    /**
     * @return list<NavigationGroupData>
     */
    private function orderedNavigationGroupData(): array
    {
        $navigationGroups = [];

        foreach ($this->defaultNavigationGroupData() as $navigationGroup) {
            $navigationGroups[$this->navigationGroupKey($navigationGroup->label)] = $navigationGroup;
        }

        foreach ($this->registeredNavigationGroups as $navigationGroupKey => $navigationGroup) {
            $navigationGroups[$navigationGroupKey] = isset($navigationGroups[$navigationGroupKey])
                ? $navigationGroups[$navigationGroupKey]->merge($navigationGroup)
                : $navigationGroup;
        }

        $orderedNavigationGroups = [];

        foreach ($navigationGroups as $navigationGroupKey => $navigationGroup) {
            if ($navigationGroup->position === NavigationGroupPositionEnum::Start) {
                $orderedNavigationGroups[$navigationGroupKey] = $navigationGroup;
            }
        }

        foreach ($navigationGroups as $navigationGroupKey => $navigationGroup) {
            if ($navigationGroup->position !== NavigationGroupPositionEnum::Start) {
                $this->insertNavigationGroup($orderedNavigationGroups, $navigationGroupKey, $navigationGroup);
            }
        }

        $this->moveSystemNavigationGroupToEnd($orderedNavigationGroups);

        return array_values($orderedNavigationGroups);
    }

    /**
     * @param  array<string, NavigationGroupData>  $orderedNavigationGroups
     */
    private function insertNavigationGroup(
        array &$orderedNavigationGroups,
        string $navigationGroupKey,
        NavigationGroupData $navigationGroup,
    ): void {
        if (
            $navigationGroup->position === NavigationGroupPositionEnum::Before
            && $navigationGroup->relativeTo !== null
        ) {
            $this->insertNavigationGroupBefore($orderedNavigationGroups, $navigationGroupKey, $navigationGroup);

            return;
        }

        if (
            $navigationGroup->position === NavigationGroupPositionEnum::After
            && $navigationGroup->relativeTo !== null
        ) {
            $this->insertNavigationGroupAfter($orderedNavigationGroups, $navigationGroupKey, $navigationGroup);

            return;
        }

        $orderedNavigationGroups[$navigationGroupKey] = $navigationGroup;
    }

    /**
     * @param  array<string, NavigationGroupData>  $orderedNavigationGroups
     */
    private function insertNavigationGroupBefore(
        array &$orderedNavigationGroups,
        string $navigationGroupKey,
        NavigationGroupData $navigationGroup,
    ): void {
        $relativeNavigationGroupKey = $this->navigationGroupKey((string) $navigationGroup->relativeTo);

        if (! array_key_exists($relativeNavigationGroupKey, $orderedNavigationGroups)) {
            $orderedNavigationGroups[$navigationGroupKey] = $navigationGroup;

            return;
        }

        $reorderedNavigationGroups = [];

        foreach ($orderedNavigationGroups as $orderedNavigationGroupKey => $orderedNavigationGroup) {
            if ($orderedNavigationGroupKey === $relativeNavigationGroupKey) {
                $reorderedNavigationGroups[$navigationGroupKey] = $navigationGroup;
            }

            $reorderedNavigationGroups[$orderedNavigationGroupKey] = $orderedNavigationGroup;
        }

        $orderedNavigationGroups = $reorderedNavigationGroups;
    }

    /**
     * @param  array<string, NavigationGroupData>  $orderedNavigationGroups
     */
    private function insertNavigationGroupAfter(
        array &$orderedNavigationGroups,
        string $navigationGroupKey,
        NavigationGroupData $navigationGroup,
    ): void {
        $relativeNavigationGroupKey = $this->navigationGroupKey((string) $navigationGroup->relativeTo);

        if (! array_key_exists($relativeNavigationGroupKey, $orderedNavigationGroups)) {
            $orderedNavigationGroups[$navigationGroupKey] = $navigationGroup;

            return;
        }

        $reorderedNavigationGroups = [];

        foreach ($orderedNavigationGroups as $orderedNavigationGroupKey => $orderedNavigationGroup) {
            $reorderedNavigationGroups[$orderedNavigationGroupKey] = $orderedNavigationGroup;

            if ($orderedNavigationGroupKey === $relativeNavigationGroupKey) {
                $reorderedNavigationGroups[$navigationGroupKey] = $navigationGroup;
            }
        }

        $orderedNavigationGroups = $reorderedNavigationGroups;
    }

    /**
     * @param  array<string, NavigationGroupData>  $orderedNavigationGroups
     */
    private function moveSystemNavigationGroupToEnd(array &$orderedNavigationGroups): void
    {
        $systemNavigationGroupKey = $this->navigationGroupKey('capell-admin::navigation.group_system');

        if (! array_key_exists($systemNavigationGroupKey, $orderedNavigationGroups)) {
            return;
        }

        $systemNavigationGroup = $orderedNavigationGroups[$systemNavigationGroupKey];

        unset($orderedNavigationGroups[$systemNavigationGroupKey]);

        $orderedNavigationGroups[$systemNavigationGroupKey] = $systemNavigationGroup;
    }

    /**
     * @param  array<int, NavigationGroup>  $navigationGroups
     * @return array<int, NavigationGroup>
     */
    private function mergeRegisteredNavigationGroupsIntoCustomGroups(array $navigationGroups): array
    {
        if ($this->registeredNavigationGroups === []) {
            return $navigationGroups;
        }

        $indexedNavigationGroups = [];

        foreach ($navigationGroups as $navigationGroup) {
            $navigationGroupKey = $this->navigationGroupKeyForFilamentGroup($navigationGroup);

            if ($navigationGroupKey === null) {
                $indexedNavigationGroups[] = $navigationGroup;

                continue;
            }

            $indexedNavigationGroups[$navigationGroupKey] = $navigationGroup;
        }

        foreach ($this->registeredNavigationGroups as $navigationGroupKey => $navigationGroup) {
            if (
                isset($indexedNavigationGroups[$navigationGroupKey])
            ) {
                $this->configureNavigationGroup($indexedNavigationGroups[$navigationGroupKey], $navigationGroup);

                continue;
            }

            $indexedNavigationGroups[$navigationGroupKey] = $this->makeNavigationGroup($navigationGroup);
        }

        return array_values($indexedNavigationGroups);
    }

    private function makeNavigationGroup(NavigationGroupData $navigationGroup): NavigationGroup
    {
        $filamentNavigationGroup = NavigationGroup::make()
            ->label(fn (): string => $this->resolveNavigationGroupLabel($navigationGroup->label));

        return $this->configureNavigationGroup($filamentNavigationGroup, $navigationGroup);
    }

    private function configureNavigationGroup(
        NavigationGroup $filamentNavigationGroup,
        NavigationGroupData $navigationGroup,
    ): NavigationGroup {
        if ($navigationGroup->collapsed) {
            $filamentNavigationGroup->collapsed();
        }

        if ($navigationGroup->icon !== null) {
            $filamentNavigationGroup->icon($navigationGroup->icon);
        }

        return $filamentNavigationGroup;
    }

    private function navigationGroupKeyForFilamentGroup(NavigationGroup $navigationGroup): ?string
    {
        $label = $navigationGroup->getLabel();

        return is_string($label) ? $this->navigationGroupKey($label) : null;
    }

    private function navigationGroupKey(string $label): string
    {
        return str($this->resolveNavigationGroupLabel($label))
            ->lower()
            ->squish()
            ->toString();
    }

    private function resolveNavigationGroupLabel(string $label): string
    {
        return str_contains($label, '::') ? (string) __($label) : $label;
    }
}
