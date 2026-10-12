<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsRecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;

it('returns empty analytics data for unauthenticated insights and recent activity', function (): void {
    $insights = (new AnalyticsInsightsFilamentWidget)->data();
    $recent = (new AnalyticsRecentActivityFilamentWidget)->data();

    expect($insights->totalViews)->toBe(0)
        ->and($recent->activePages)->toBe(0);
});

it('hides analytics dashboard widgets from unauthenticated users', function (): void {
    // Anonymous widgets are denied before mounting, so assert their public visibility contract.
    expect(AnalyticsOverviewFilamentWidget::canView())->toBeFalse()
        ->and(AnalyticsTrendFilamentWidget::canView())->toBeFalse()
        ->and(AnalyticsInsightsFilamentWidget::canView())->toBeFalse()
        ->and(AnalyticsRecentActivityFilamentWidget::canView())->toBeFalse();
});
