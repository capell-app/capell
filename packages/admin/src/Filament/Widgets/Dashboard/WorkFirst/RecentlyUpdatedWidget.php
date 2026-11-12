<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst;

use Capell\Admin\Actions\Dashboard\BuildRecentlyUpdatedAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\DashboardRecentlyUpdatedData;
use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Admin\Filament\Concerns\WorkFirst\ChecksWorkFirstWidgetAccess;
use Capell\Admin\Filament\Concerns\WorkFirst\HasPersonalDashboardSize;
use Capell\Admin\Filament\Widgets\Dashboard\FirstRunChecklistFilamentWidget;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;
use Override;

final class RecentlyUpdatedWidget extends Widget implements CapellFilamentWidgetContract
{
    use ChecksWorkFirstWidgetAccess;
    use HasDashboardDateRange;
    use HasPersonalDashboardSize;

    protected static string $workFirstSettingsKey = 'recently_updated';

    protected string $view = 'capell-admin::widgets.dashboard.work-first-recently-updated';

    /** Renders with the page so the dashboard never shows an empty placeholder card while it loads. */
    protected static bool $isLazy = false;

    #[Override]
    public static function canView(): bool
    {
        // The standard dashboard places this only while a site is being set up;
        // otherwise it belongs to the work-first layout, as before.
        return self::widgetEnabled('recently_updated', requiresWorkFirst: ! FirstRunChecklistFilamentWidget::replacesDashboard());
    }

    #[Computed]
    public function data(): DashboardRecentlyUpdatedData
    {
        $actor = auth()->user();

        return $actor === null || ! self::canView() ? new DashboardRecentlyUpdatedData(0, []) : resolve(BuildRecentlyUpdatedAction::class)->handle($actor, $this->getDashboardSiteId(), $this->getDashboardLanguage(), $this->dashboardSize === 'wide' ? 8 : 5);
    }
}
