<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsRecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

it('returns empty analytics state for unauthenticated dashboard widgets', function (): void {
    $insights = (new AnalyticsInsightsFilamentWidget)->data();
    $recent = (new AnalyticsRecentActivityFilamentWidget)->data();

    $overview = Livewire::test(AnalyticsOverviewFilamentWidget::class);
    $trend = Livewire::test(AnalyticsTrendFilamentWidget::class);

    $overview->assertDontSee((string) __('capell-admin::dashboard.analytics_views'));
    $trend->html();

    $trendWidget = $trend->instance();
    Assert::assertInstanceOf(AnalyticsTrendFilamentWidget::class, $trendWidget);

    expect($insights->totalViews)->toBe(0)
        ->and($recent->activePages)->toBe(0)
        ->and($trendWidget->dataChecksum)->toBe(md5(json_encode(
            ['labels' => [], 'datasets' => []],
            JSON_THROW_ON_ERROR,
        )));
});
