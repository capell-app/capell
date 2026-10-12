<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsInsightsFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsOverviewFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsRecentActivityFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\AnalyticsTrendFilamentWidget;
use Livewire\Livewire;

it('returns empty analytics state for unauthenticated dashboard widgets', function (): void {
    $insights = (new AnalyticsInsightsFilamentWidget)->data();
    $recent = (new AnalyticsRecentActivityFilamentWidget)->data();

    $overview = Livewire::test(AnalyticsOverviewFilamentWidget::class)->html();
    $trend = Livewire::test(AnalyticsTrendFilamentWidget::class)->html();
    // Inspect the chart data delivered to the browser, rather than its protected builder.
    expect(preg_match("/cachedData:\\s*JSON\\.parse\\('([^']*)'\\)/", $trend, $matches))->toBe(1);
    throw_unless(isset($matches[1]), RuntimeException::class, 'Chart data was not delivered to the browser.');
    $chartJson = json_decode('"' . $matches[1] . '"', flags: JSON_THROW_ON_ERROR);
    $chart = json_decode((string) $chartJson, true, flags: JSON_THROW_ON_ERROR);

    expect($insights->totalViews)->toBe(0)
        ->and($recent->activePages)->toBe(0)
        ->and($overview)->not->toContain('fi-wi-stats-overview-stat')
        ->and($chart)->toBe(['labels' => [], 'datasets' => []]);
});
