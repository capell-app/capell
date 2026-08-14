<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire\Header;

use Capell\Admin\Data\ResolvedAdminWorkspaceItemData;
use Capell\Admin\Enums\AdminWorkspaceEnum;
use Capell\Admin\Support\Workspace\AdminWorkspaceNavigator;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

final class AdminWorkspaceSwitcher extends Component
{
    public string $workspace = AdminWorkspaceEnum::All->value;

    public string $search = '';

    public function setWorkspace(string $workspace): void
    {
        if (AdminWorkspaceEnum::tryFrom($workspace) === null) {
            return;
        }

        $this->workspace = $workspace;
    }

    public function togglePin(string $key): void
    {
        resolve(AdminWorkspaceNavigator::class)->togglePin($this->actor(), $key, $this->currentWorkspace());
    }

    public function recordVisit(string $key): void
    {
        resolve(AdminWorkspaceNavigator::class)->recordVisit($this->actor(), $key, $this->currentWorkspace());
    }

    public function isPinned(string $key): bool
    {
        return in_array($key, $this->preferenceKeys('pinnedKeys'), true);
    }

    /** @return list<ResolvedAdminWorkspaceItemData> */
    #[Computed]
    public function items(): array
    {
        return $this->filterItems(resolve(AdminWorkspaceNavigator::class)->items($this->actor(), $this->currentWorkspace()));
    }

    /** @return list<ResolvedAdminWorkspaceItemData> */
    #[Computed]
    public function pinnedItems(): array
    {
        return $this->orderedPreferences('pinnedKeys');
    }

    /** @return list<ResolvedAdminWorkspaceItemData> */
    #[Computed]
    public function recentItems(): array
    {
        return $this->orderedPreferences(
            property: 'recentKeys',
            excludedKeys: $this->preferenceKeys('pinnedKeys'),
        );
    }

    /** @return list<ResolvedAdminWorkspaceItemData> */
    #[Computed]
    public function toolItems(): array
    {
        $excludedKeys = array_fill_keys([
            ...$this->preferenceKeys('pinnedKeys'),
            ...$this->preferenceKeys('recentKeys'),
        ], true);

        return array_values(array_filter(
            $this->items(),
            static fn (ResolvedAdminWorkspaceItemData $item): bool => ! isset($excludedKeys[$item->key]),
        ));
    }

    public function render(): View
    {
        return view('capell-admin::livewire.header.admin-workspace-switcher', [
            'workspaces' => AdminWorkspaceEnum::cases(),
        ]);
    }

    private function currentWorkspace(): AdminWorkspaceEnum
    {
        return AdminWorkspaceEnum::tryFrom($this->workspace) ?? AdminWorkspaceEnum::All;
    }

    private function actor(): ?Authenticatable
    {
        try {
            $actor = Filament::auth()->user();
        } catch (Throwable) {
            return null;
        }

        return $actor instanceof Authenticatable ? $actor : null;
    }

    /**
     * @param  list<string>  $excludedKeys
     * @return list<ResolvedAdminWorkspaceItemData>
     */
    private function orderedPreferences(string $property, array $excludedKeys = []): array
    {
        $keys = $this->preferenceKeys($property);
        $byKey = [];

        foreach ($this->items() as $item) {
            $byKey[$item->key] = $item;
        }

        return array_values(array_filter(array_map(
            static fn (string $key): ?ResolvedAdminWorkspaceItemData => in_array($key, $excludedKeys, true) ? null : ($byKey[$key] ?? null),
            $keys,
        )));
    }

    /** @return list<string> */
    private function preferenceKeys(string $property): array
    {
        $state = resolve(AdminWorkspaceNavigator::class)->state($this->actor(), $this->currentWorkspace());

        return $state->{$property};
    }

    /**
     * @param  list<ResolvedAdminWorkspaceItemData>  $items
     * @return list<ResolvedAdminWorkspaceItemData>
     */
    private function filterItems(array $items): array
    {
        $query = mb_strtolower(trim($this->search));

        if ($query === '') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (ResolvedAdminWorkspaceItemData $item): bool => str_contains(mb_strtolower($item->label), $query)
                || ($item->description !== null && str_contains(mb_strtolower($item->description), $query)),
        ));
    }
}
