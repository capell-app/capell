<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildPersonalDraftsAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Contracts\Dashboard\DashboardWorkDataProvider;
use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Capell\Admin\Data\Dashboard\DashboardWorkItemData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Concerns\WorkFirst\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Concerns\WorkFirst\HasPersonalDashboardSize;
use Capell\Admin\Support\Dashboard\DashboardDeadline;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Override;

/**
 * @property-read DashboardWorkData $assignments
 * @property-read DashboardWorkData $data
 */
final class PersonalDraftsWidget extends Widget implements CapellFilamentWidgetContract
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;

    /** @var list<int> */
    #[Locked]
    public array $workOrder = [];

    #[Locked]
    public ?string $workScope = null;

    protected static string $workFirstSettingsKey = 'your_work';

    protected string $view = 'capell-admin::widgets.dashboard.work-first-personal-drafts';

    #[Override]
    public static function canView(): bool
    {
        return self::widgetEnabled('your_work');
    }

    #[Computed]
    public function assignments(): DashboardWorkData
    {
        $actor = auth()->user();

        if ($actor === null || ! self::canView()) {
            return new DashboardWorkData(false, 0, []);
        }

        return resolve(DashboardWorkDataProvider::class)->build(
            $actor,
            $this->getDashboardSiteId(),
            100,
            $this->getDashboardLanguage(),
            $this->workOrder,
        );
    }

    #[Computed]
    public function data(): DashboardWorkData
    {
        $actor = auth()->user();

        if ($actor === null || ! self::canView()) {
            return new DashboardWorkData(false, 0, []);
        }

        return resolve(BuildPersonalDraftsAction::class)->handle($actor, $this->getDashboardSiteId(), 100, $this->getDashboardLanguage(), $this->workOrder);
    }

    /** @return list<DashboardWorkItemData> */
    #[Computed]
    public function workRows(): array
    {
        $items = $this->currentWorkRows();
        $this->initialiseWorkOrder($items);
        $positions = array_flip($this->workOrder);
        $visible = array_values(array_filter($items, static fn (DashboardWorkItemData $item): bool => isset($positions[$item->pageId])));
        usort($visible, static fn (DashboardWorkItemData $left, DashboardWorkItemData $right): int => $positions[$left->pageId] <=> $positions[$right->pageId]);

        return array_slice($visible, 0, $this->dashboardSize === 'wide' ? 8 : 5);
    }

    #[Computed]
    public function pendingWork(): int
    {
        $items = $this->currentWorkRows();
        $this->initialiseWorkOrder($items);

        return count(array_diff(array_map(static fn (DashboardWorkItemData $item): int => $item->pageId, $items), $this->workOrder));
    }

    public function applyWorkUpdates(): void
    {
        $items = $this->currentWorkRows();
        $this->initialiseWorkOrder($items);
        $this->workOrder = array_map(static fn (DashboardWorkItemData $item): int => $item->pageId, $items);
        unset($this->workRows, $this->pendingWork);
    }

    /** @return list<DashboardWorkItemData> */
    private function currentWorkRows(): array
    {
        $items = [];
        foreach ([...$this->assignments->items, ...$this->data->items] as $item) {
            $items[$item->pageId] ??= $item;
        }

        $items = array_values($items);
        usort($items, static fn (DashboardWorkItemData $left, DashboardWorkItemData $right): int => [
            $left->deadline === null ? 1 : 0, DashboardDeadline::timestamp($left->deadline), $left->reason === 'changes_requested' ? 0 : 1, $left->pageId,
        ] <=> [
            $right->deadline === null ? 1 : 0, DashboardDeadline::timestamp($right->deadline), $right->reason === 'changes_requested' ? 0 : 1, $right->pageId,
        ]);

        return $items;
    }

    /** @param list<DashboardWorkItemData> $items */
    private function initialiseWorkOrder(array $items): void
    {
        $scope = hash('sha256', json_encode([auth()->id(), $this->getDashboardSiteId(), $this->getDashboardLanguage(), $this->dashboardSize], JSON_THROW_ON_ERROR));
        if ($this->workScope !== $scope) {
            $this->workScope = $scope;
            $this->workOrder = array_map(static fn (DashboardWorkItemData $item): int => $item->pageId, $items);
        }
    }
}
