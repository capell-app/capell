<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsRecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;

it('returns empty analytics state for unauthenticated dashboard widgets', function (): void {
    $insights = (new AnalyticsInsightsFilamentWidget)->data();
    $recent = (new AnalyticsRecentActivityFilamentWidget)->data();

    $overviewMethod = new ReflectionMethod(AnalyticsOverviewFilamentWidget::class, 'getStats');

    $trendMethod = new ReflectionMethod(AnalyticsTrendFilamentWidget::class, 'getData');

    expect($insights->totalViews)->toBe(0)
        ->and($recent->activePages)->toBe(0)
        ->and($overviewMethod->invoke(new AnalyticsOverviewFilamentWidget))->toBe([])
        ->and($trendMethod->invoke(new AnalyticsTrendFilamentWidget))->toBe([
            'labels' => [],
            'datasets' => [],
        ]);
});
