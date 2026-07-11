<?php

declare(strict_types=1);

use Capell\Admin\Data\Dashboard\ContentHealthData;
use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Dashboard\NullContentHealthDataProvider;
use Capell\Admin\Support\Dashboard\NullSiteStatsDataProvider;

it('returns empty site stats from the null provider', function (): void {
    $stats = (new NullSiteStatsDataProvider)->build('this_month');

    expect($stats)->toBeInstanceOf(SiteStatsData::class)
        ->and($stats->workQueueCount)->toBe(0)
        ->and($stats->publishedCount)->toBe(0)
        ->and($stats->sparklinePublished)->toBe([]);
});

it('returns empty content health from the null provider', function (): void {
    $health = (new NullContentHealthDataProvider)->build();

    expect($health)->toBeInstanceOf(ContentHealthData::class)
        ->and($health->issues)->toHaveCount(0);
});

it('fails clearly when no page exporter is registered', function (): void {
    $exporter = new NullPageExporter;

    expect(fn (): mixed => $exporter->exportPages([1], []))
        ->toThrow(RuntimeException::class, 'No page exporter is registered.');

    expect(fn (): mixed => $exporter->exportSites([1], []))
        ->toThrow(RuntimeException::class, 'No page exporter is registered.');
});
