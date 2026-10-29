<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\WorkFirst;

use Livewire\Attributes\Locked;

trait HasStableDashboardOrder
{
    /** @var list<string> */
    #[Locked]
    public array $dashboardItemOrder = [];

    #[Locked]
    public ?string $dashboardItemScope = null;

    public function applyDashboardUpdates(): void
    {
        $this->dashboardItemScope = null;
        unset($this->items, $this->pendingItems);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function stableDashboardKeys(array $keys, string $scope): array
    {
        if ($this->dashboardItemScope !== $scope) {
            $this->dashboardItemScope = $scope;
            $this->dashboardItemOrder = $keys;
        }

        return array_values(array_intersect($this->dashboardItemOrder, $keys));
    }

    /** @param list<string> $keys */
    protected function countPendingDashboardKeys(array $keys, string $scope): int
    {
        $this->stableDashboardKeys($keys, $scope);

        return count(array_diff($keys, $this->dashboardItemOrder));
    }
}
