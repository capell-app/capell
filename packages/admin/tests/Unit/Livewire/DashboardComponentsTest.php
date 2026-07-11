<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Dashboard\SiteStatsDataProvider;
use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Widgets\Dashboard\SiteStatsOverviewFilamentWidget;
use Capell\Admin\Livewire\GlobalCommandPalette;
use Capell\Admin\Livewire\InfoBanner;
use Illuminate\Support\Facades\DB;

it('filters global command palette commands by label or description and resets on close', function (): void {
    CapellAdmin::shouldReceive('getPaletteCommands')
        ->andReturn([
            new PaletteCommandData(id: 'create', label: 'Create page', description: 'Add content'),
            new PaletteCommandData(id: 'media', label: 'Media library', description: 'Find images'),
        ]);

    $palette = new GlobalCommandPalette;

    expect(array_column($palette->filteredCommands(), 'id'))->toBe(['create', 'media']);

    $palette->query = 'images';

    expect(array_column($palette->filteredCommands(), 'id'))->toBe(['media']);

    $palette->toggle();
    expect($palette->open)->toBeTrue();

    $palette->query = 'page';
    $palette->toggle();

    expect($palette->open)->toBeFalse()
        ->and($palette->query)->toBe('');
});

it('hides info banners for dismissed hints and records new dismissals', function (): void {
    $user = test()->createUser();
    DB::table('users')
        ->where('id', $user->getKey())
        ->update(['dismissed_hints' => json_encode(['already-seen'])]);
    test()->actingAs($user);

    $dismissedBanner = new InfoBanner;
    $dismissedBanner->hintKey = 'already-seen';
    $dismissedBanner->mount();

    expect($dismissedBanner->visible)->toBeFalse();

    $visibleBanner = new InfoBanner;
    $visibleBanner->hintKey = 'new-hint';
    $visibleBanner->mount();
    $visibleBanner->dismiss();

    $dismissedHints = json_decode((string) DB::table('users')->where('id', $user->getKey())->value('dismissed_hints'), true);

    expect($visibleBanner->visible)->toBeFalse()
        ->and($dismissedHints)->toContain('already-seen')
        ->and($dismissedHints)->toContain('new-hint');
});

it('builds site stats from the configured provider and updates the dashboard period', function (): void {
    $provider = new class implements SiteStatsDataProvider
    {
        public string $period = '';

        public int $builds = 0;

        public function build(string $period): SiteStatsData
        {
            $this->builds++;
            $this->period = $period;

            return new SiteStatsData(
                workQueueCount: 2,
                publishedCount: 15,
                sparklinePublished: [1, 3, 5],
                pendingCount: 1,
                expiredCount: 0,
            );
        }
    };

    app()->instance(SiteStatsDataProvider::class, $provider);

    $widget = new SiteStatsOverviewFilamentWidget;
    $widget->onDashboardFilterChanged('last_30_days');

    $method = new ReflectionMethod(SiteStatsOverviewFilamentWidget::class, 'getStats');
    $stats = $method->invoke($widget);
    $method->invoke($widget);

    expect($widget->dashboardPeriod)->toBe('last_30_days')
        ->and($stats)->toHaveCount(4)
        ->and($stats[0]->getLabel())->toBe(__('capell-admin::dashboard.stat_work_queue'))
        ->and($stats[0]->getValue())->toBe('2')
        ->and($stats[0]->getColor())->toBe('warning')
        ->and($stats[1]->getChart())->toBe([1.0, 3.0, 5.0])
        ->and($stats[2]->getColor())->toBe('warning')
        ->and($stats[3]->getColor())->toBe('success')
        ->and($provider->builds)->toBe(1);
});
