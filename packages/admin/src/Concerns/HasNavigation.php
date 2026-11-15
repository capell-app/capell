<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use BackedEnum;
use Capell\Admin\Data\NavigationGroupData;
use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Enums\NavigationGroupPositionEnum;
use Capell\Core\Enums\ExtensionContributionType;
use Deprecated;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Log;

trait HasNavigation
{
    /** @var array<int, NavigationGroup> */
    protected array $navigationGroups = [];

    /** @var array<string, string> replaced item key => replacement item key */
    protected array $navigationReplacements = [];

    /**
     * @return array<int, NavigationGroup>
     */
    public function getDefaultNavigationGroups(): array
    {
        return array_map(
            fn (NavigationGroupEnum $case): NavigationGroup => NavigationGroup::make()
                ->label(fn (): string => $case->getLabel())
                ->collapsed($case->isCollapsedByDefault()),
            NavigationGroupEnum::cases(),
        );
    }

    /**
     * @return array<int, NavigationGroup>
     */
    public function getNavigationGroups(): array
    {
        return $this->navigationGroups !== [] ? $this->navigationGroups : $this->getDefaultNavigationGroups();
    }

    /**
     * @param  array<int, NavigationGroup>  $navigationGroups
     */
    public function setNavigationGroups(array $navigationGroups): static
    {
        $this->navigationGroups = $navigationGroups;

        return $this;
    }

    #[Deprecated(message: 'Sidebar groups are fixed: return a NavigationGroupEnum case from getNavigationGroup(). This call no longer adds a group.')]
    public function registerNavigationGroup(
        string $label,
        null|string|BackedEnum $icon = null,
        NavigationGroupPositionEnum $position = NavigationGroupPositionEnum::End,
        ?string $relativeTo = null,
        bool $collapsed = true,
    ): static {
        Log::debug('Deprecated: CapellAdmin::registerNavigationGroup() no longer adds sidebar groups.', ['group' => $label]);
        $this->adminReceipt(
            ExtensionContributionType::AdminActionExtender,
            'navigation-group:' . $label,
            NavigationGroupData::class,
        );

        return $this;
    }

    /**
     * Shows the replacement item in the replaced item's place whenever the
     * replacement is registered, instead of listing both. Keys are the
     * navigation item keys, which for resources and pages are their class names.
     */
    public function replaceNavigationItem(string $replacedKey, string $replacementKey): static
    {
        $this->navigationReplacements[$replacedKey] = $replacementKey;
        $this->adminReceipt(
            ExtensionContributionType::AdminActionExtender,
            'navigation-replacement:' . $replacedKey,
            $replacementKey,
        );

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getNavigationReplacements(): array
    {
        return $this->navigationReplacements;
    }

    /**
     * @return array<int, NavigationItem>
     */
    public function getNavigationItems(): array
    {
        return [];
    }
}
