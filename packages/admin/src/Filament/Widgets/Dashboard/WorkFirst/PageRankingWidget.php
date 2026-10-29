<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildPageRankingsAction;
use Capell\Admin\Actions\Dashboard\CanViewWorkFirstAnalyticsAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\DashboardPageRankingsData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Concerns\WorkFirst\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Concerns\WorkFirst\HasPersonalDashboardSize;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;

abstract class PageRankingWidget extends Widget implements CapellFilamentWidgetContract
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;

    protected string $view = 'capell-admin::widgets.dashboard.work-first-page-ranking';

    abstract protected static function widgetKey(): string;

    abstract public function isRising(): bool;

    public static function settingsKey(): string
    {
        return static::widgetKey();
    }

    #[Override]
    public static function canView(): bool
    {
        $actor = auth()->user();
        $switches = config('capell-admin.dashboard.work_first.widgets', []);

        return config('capell-admin.dashboard.work_first.enabled', false) === true
            && (! is_array($switches) || (($switches[static::widgetKey()] ?? true) !== false && ($switches[static::class] ?? true) !== false))
            && $actor !== null
            && resolve(CanViewWorkFirstAnalyticsAction::class)->handle($actor);
    }

    #[Computed]
    public function data(): DashboardPageRankingsData
    {
        $actor = auth()->user();
        if ($actor === null || ! static::canView()) {
            return new DashboardPageRankingsData('unauthorised', '', '', '', [], null);
        }

        $days = match ($this->getDashboardPeriod()) {
            'today' => 1,
            'this_week' => 7,
            'this_year' => 365,
            default => 30,
        };

        return resolve(BuildPageRankingsAction::class)->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage(), $days, $this->isRising(), $this->dashboardSize === 'wide' ? 8 : 5);
    }
}
