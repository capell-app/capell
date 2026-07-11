<?php

declare(strict_types=1);

use Capell\Admin\Data\Dashboard\ContentHealthData;
use Capell\Admin\Data\Dashboard\ContentHealthIssueData;
use Capell\Admin\Data\Dashboard\SiteTrafficData;
use Capell\Admin\Data\Dashboard\TrafficDayData;
use Spatie\LaravelData\DataCollection;

it('hydrates content health dashboard data with typed issues', function (): void {
    $issue = new ContentHealthIssueData(
        id: 'missing-alt-text',
        label: 'Missing alt text',
        count: 3,
        filterUrl: '/admin/media?filter=missing-alt-text',
    );

    $health = new ContentHealthData(ContentHealthIssueData::collect([$issue], DataCollection::class));
    $firstIssue = expectPresent(firstDataItem($health->issues));

    expect($health->issues)->toHaveCount(1)
        ->and(firstDataItem($health->issues))->toBe($issue)
        ->and($firstIssue->filterUrl)->toBe('/admin/media?filter=missing-alt-text');
});

it('hydrates site traffic dashboard data without losing daily buckets', function (): void {
    $days = [
        new TrafficDayData(date: '2026-05-05', visitors: 12, pageviews: 35),
        new TrafficDayData(date: '2026-05-06', visitors: 18, pageviews: 49),
    ];

    $traffic = new SiteTrafficData(
        days: $days,
        totalVisitors: 30,
        totalPageviews: 84,
        windowDays: 7,
        bucket: 'day',
    );

    expect($traffic->days)->toBe($days)
        ->and($traffic->totalVisitors)->toBe(30)
        ->and($traffic->totalPageviews)->toBe(84)
        ->and($traffic->windowDays)->toBe(7)
        ->and($traffic->bucket)->toBe('day');
});
