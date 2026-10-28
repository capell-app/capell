<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildPageViewsAction;
use Capell\Admin\Actions\Dashboard\BuildVisitorsSnapshotAction;
use Capell\Admin\Actions\Dashboard\CanViewWorkFirstAnalyticsAction;
use Capell\Admin\Data\Dashboard\DashboardPageViewsData;
use Capell\Admin\Data\Dashboard\DashboardVisitorsData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns\HasPersonalDashboardSize;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;

final class PageViewsWidget extends Widget
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;

    protected string $view = 'capell-admin::widgets.dashboard.work-first-page-views';

    #[Override]
    public static function canView(): bool
    {
        $actor = auth()->user();
        $switches = config('capell-admin.dashboard.work_first.widgets', []);

        return config('capell-admin.dashboard.work_first.enabled', false) === true
            && (! is_array($switches) || ($switches['page_views'] ?? true) !== false)
            && $actor !== null
            && resolve(CanViewWorkFirstAnalyticsAction::class)->handle($actor);
    }

    #[Computed]
    public function data(): DashboardPageViewsData
    {
        $actor = auth()->user();
        if ($actor === null || ! self::canView()) {
            return new DashboardPageViewsData('unauthorised', null, null, [], null, 0, 0);
        }

        return resolve(BuildPageViewsAction::class)->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage(), $this->periodDays());
    }

    #[Computed]
    public function visitors(): ?DashboardVisitorsData
    {
        $actor = auth()->user();
        $switches = config('capell-admin.dashboard.work_first.widgets', []);
        if ($actor === null || ! self::canView() || (is_array($switches) && ($switches['visitors'] ?? true) === false)) {
            return null;
        }

        return resolve(BuildVisitorsSnapshotAction::class)->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage(), $this->periodDays());
    }

    public function showVisitorScopeNote(): bool
    {
        $mapping = config('capell-admin.dashboard.work_first.analytics.visitors', []);
        $switches = config('capell-admin.dashboard.work_first.widgets', []);

        return self::canView() && $this->getDashboardSiteId() === null
            && is_array($mapping) && ($mapping['enabled'] ?? false) === true
            && (! is_array($switches) || ($switches['visitors'] ?? true) !== false);
    }

    private function periodDays(): int
    {
        return match ($this->getDashboardPeriod()) {
            'today' => 1,
            'this_week' => 7,
            'this_year' => 365,
            default => 30,
        };
    }
}
