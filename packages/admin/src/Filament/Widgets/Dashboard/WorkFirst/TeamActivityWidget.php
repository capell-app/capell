<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildWorkFirstActivityAction;
use Capell\Admin\Data\Dashboard\DashboardActivityItemData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\HasPersonalDashboardSize;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\HasStableDashboardOrder;
use Capell\Admin\Support\Dashboard\DashboardSiteEvaluator;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Override;
use Spatie\Activitylog\Models\Activity;

/** @property-read list<DashboardActivityItemData> $currentItems */
final class TeamActivityWidget extends Widget
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;
    use HasStableDashboardOrder;

    protected string $view = 'capell-admin::widgets.dashboard.work-first-team-activity';

    #[Override]
    public static function canView(): bool
    {
        $actor = auth()->user();
        if (! self::widgetEnabled('team_activity') || $actor === null) {
            return false;
        }

        $evaluator = resolve(DashboardSiteEvaluator::class);

        return collect($evaluator->siteIds($actor))->contains(fn (int $siteId): bool => $evaluator->allows($actor, $siteId, fn (): bool => Gate::forUser($actor)->allows('viewAny', Activity::class)));
    }

    /** @return list<DashboardActivityItemData> */
    #[Computed]
    public function items(): array
    {
        $items = $this->currentItems;
        $keys = array_map(static fn (DashboardActivityItemData $item): string => $item->key, $items);
        $order = $this->stableDashboardKeys($keys, $this->scopeKey());
        $byKey = array_column($items, null, 'key');

        return array_slice(array_values(array_map(static fn (string $key): DashboardActivityItemData => $byKey[$key], $order)), 0, $this->dashboardSize === 'wide' ? 8 : 5);
    }

    #[Computed]
    public function pendingItems(): int
    {
        return $this->countPendingDashboardKeys(array_map(static fn (DashboardActivityItemData $item): string => $item->key, $this->currentItems), $this->scopeKey());
    }

    /** @return list<DashboardActivityItemData> */
    #[Computed]
    public function currentItems(): array
    {
        $actor = auth()->user();

        return ! self::canView() || $actor === null ? [] : resolve(BuildWorkFirstActivityAction::class)
            ->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage(), 100, $this->dashboardItemOrder);
    }

    public function activityUrl(): string
    {
        return ActivityResource::getUrl();
    }

    private function scopeKey(): string
    {
        return auth()->id() . ':' . ($this->getDashboardSiteId() ?? 'all') . ':' . ($this->getDashboardLanguage() ?? 'all') . ':' . $this->dashboardSize;
    }
}
