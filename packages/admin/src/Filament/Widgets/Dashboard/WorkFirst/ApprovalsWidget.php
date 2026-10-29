<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Contracts\Dashboard\DashboardApprovalsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardApprovalItemData;
use Capell\Admin\Data\Dashboard\DashboardApprovalsData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\HasPersonalDashboardSize;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Override;

/** @property-read DashboardApprovalsData $data */
final class ApprovalsWidget extends Widget
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;

    /** @var list<string> */
    #[Locked]
    public array $approvalOrder = [];

    #[Locked]
    public ?string $approvalScope = null;

    /** @var list<int> */
    #[Locked]
    public array $retainedApprovalPages = [];

    protected string $view = 'capell-admin::widgets.dashboard.work-first-approvals';

    #[Override]
    public static function canView(): bool
    {
        $switches = config('capell-admin.dashboard.work_first.widgets', []);

        $actor = auth()->user();
        if (config('capell-admin.dashboard.work_first.enabled', false) !== true
            || (is_array($switches) && (($switches['approvals'] ?? true) === false || ($switches[self::class] ?? true) === false))
            || $actor === null) {
            return false;
        }

        $access = resolve(DashboardApprovalsDataProvider::class)->build($actor, null, 0);

        return $access->available && $access->canReview;
    }

    #[Computed]
    public function data(): DashboardApprovalsData
    {
        $actor = auth()->user();

        if ($actor === null || ! self::canView()) {
            return new DashboardApprovalsData(false, false, false, 0, 0, null, []);
        }

        return resolve(DashboardApprovalsDataProvider::class)->build(
            $actor,
            $this->getDashboardSiteId(),
            100,
            $this->getDashboardLanguage(),
            $this->retainedApprovalPages,
        );
    }

    /** @return list<DashboardApprovalItemData> */
    #[Computed]
    public function approvalRows(): array
    {
        $items = $this->data->canReview ? $this->data->items : [];
        $this->initialiseApprovalOrder($items);
        $positions = array_flip($this->approvalOrder);
        $visible = array_values(array_filter($items, fn (DashboardApprovalItemData $item): bool => isset($positions[$this->approvalKey($item)])));
        usort($visible, fn (DashboardApprovalItemData $left, DashboardApprovalItemData $right): int => $positions[$this->approvalKey($left)] <=> $positions[$this->approvalKey($right)]);

        return array_slice($visible, 0, $this->dashboardSize === 'wide' ? 8 : 5);
    }

    #[Computed]
    public function pendingApprovals(): int
    {
        $items = $this->data->canReview ? $this->data->items : [];
        $this->initialiseApprovalOrder($items);

        return count(array_diff(array_map($this->approvalKey(...), $items), $this->approvalOrder));
    }

    public function applyApprovalUpdates(): void
    {
        $items = $this->data->canReview ? $this->data->items : [];
        $this->initialiseApprovalOrder($items);
        $this->approvalOrder = array_values(array_map($this->approvalKey(...), $items));
        $this->retainedApprovalPages = array_values(array_map(static fn (DashboardApprovalItemData $item): int => $item->pageId, $items));
        unset($this->approvalRows, $this->pendingApprovals);
    }

    private function approvalKey(DashboardApprovalItemData $item): string
    {
        return hash('sha256', $item->pageId . ':' . $item->reviewUrl);
    }

    /** @param list<DashboardApprovalItemData> $items */
    private function initialiseApprovalOrder(array $items): void
    {
        $scope = hash('sha256', json_encode([auth()->id(), $this->getDashboardSiteId(), $this->getDashboardLanguage(), $this->dashboardSize], JSON_THROW_ON_ERROR));
        if ($this->approvalScope !== $scope) {
            $this->approvalScope = $scope;
            $this->approvalOrder = array_values(array_map($this->approvalKey(...), $items));
            $this->retainedApprovalPages = array_values(array_map(static fn (DashboardApprovalItemData $item): int => $item->pageId, $items));
        }
    }
}
