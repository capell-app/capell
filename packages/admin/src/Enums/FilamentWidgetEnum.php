<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Admin\Filament\Widgets\Dashboard\CapellAccountFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\CapellInfoFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\PageStatusFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\SiteStatsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\UpdateAdvisoryFilamentWidget;
use Filament\Support\Contracts\HasLabel;

enum FilamentWidgetEnum: string implements HasLabel
{
    case AccountFilamentWidget = CapellAccountFilamentWidget::class;
    case InfoFilamentWidget = CapellInfoFilamentWidget::class;
    case ListPagesFilamentWidget = ListPagesFilamentWidget::class;
    case MyWorkQueueFilamentWidget = MyWorkQueueFilamentWidget::class;
    case PageStatusFilamentWidget = PageStatusFilamentWidget::class;
    case RecentlyPublishedFilamentWidget = RecentlyPublishedFilamentWidget::class;
    case SiteStatsOverviewFilamentWidget = SiteStatsOverviewFilamentWidget::class;
    case UpdateAdvisoryFilamentWidget = UpdateAdvisoryFilamentWidget::class;

    public function getLabel(): string
    {
        return match ($this) {
            self::AccountFilamentWidget => __('capell-admin::dashboard.widget_account'),
            self::InfoFilamentWidget => __('capell-admin::dashboard.widget_filament_info'),
            self::ListPagesFilamentWidget => __('capell-admin::dashboard.widget_list_pages'),
            self::MyWorkQueueFilamentWidget => __('capell-admin::dashboard.widget_my_work_queue'),
            self::PageStatusFilamentWidget => __('capell-admin::dashboard.widget_page_status'),
            self::RecentlyPublishedFilamentWidget => __('capell-admin::dashboard.widget_recently_published'),
            self::SiteStatsOverviewFilamentWidget => __('capell-admin::dashboard.widget_site_stats'),
            self::UpdateAdvisoryFilamentWidget => __('capell-admin::dashboard.widget_update_advisories'),
        };
    }
}
