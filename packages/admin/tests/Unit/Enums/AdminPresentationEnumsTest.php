<?php

declare(strict_types=1);

use Capell\Admin\Enums\CacheToClearEnum;
use Capell\Admin\Enums\FilamentWidgetEnum;
use Capell\Admin\Filament\Widgets\Dashboard\CapellAccountFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\CapellInfoFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;

it('labels cache clearing choices', function (): void {
    expect(CacheToClearEnum::Page->getLabel())->toBe('HTML page cache')
        ->and(CacheToClearEnum::Config->getLabel())->toBe('Config cache')
        ->and(CacheToClearEnum::Views->getLabel())->toBe('Views cache');
});

it('labels dashboard Filament widgets by registered widget class', function (): void {
    expect(FilamentWidgetEnum::AccountFilamentWidget->value)->toBe(CapellAccountFilamentWidget::class)
        ->and(FilamentWidgetEnum::InfoFilamentWidget->value)->toBe(CapellInfoFilamentWidget::class)
        ->and(FilamentWidgetEnum::ListPagesFilamentWidget->value)->toBe(ListPagesFilamentWidget::class)
        ->and(FilamentWidgetEnum::AccountFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_account'))
        ->and(FilamentWidgetEnum::InfoFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_filament_info'))
        ->and(FilamentWidgetEnum::ListPagesFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_list_pages'))
        ->and(FilamentWidgetEnum::MyWorkQueueFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_my_work_queue'))
        ->and(FilamentWidgetEnum::PageStatusFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_page_status'))
        ->and(FilamentWidgetEnum::RecentlyPublishedFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_recently_published'))
        ->and(FilamentWidgetEnum::SiteStatsOverviewFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_site_stats'))
        ->and(FilamentWidgetEnum::UpdateAdvisoryFilamentWidget->getLabel())->toBe(__('capell-admin::dashboard.widget_update_advisories'));
});
