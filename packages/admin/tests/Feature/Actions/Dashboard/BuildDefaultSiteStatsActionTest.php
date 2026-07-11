<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildDefaultSiteStatsAction;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;

it('builds default CMS stats from core pages', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-03 12:00:00'));

    Page::factory()->published(CarbonImmutable::parse('2026-05-01 09:00:00'))->create();
    Page::factory()->createOne([
        'visible_from' => CarbonImmutable::parse('2026-05-05 09:00:00'),
        'visible_until' => null,
    ]);
    Page::factory()->createOne([
        'visible_from' => CarbonImmutable::parse('2026-04-01 09:00:00'),
        'visible_until' => CarbonImmutable::parse('2026-05-02 09:00:00'),
    ]);

    $data = BuildDefaultSiteStatsAction::run('this_week');

    expect($data->publishedCount)->toBe(1)
        ->and($data->workQueueCount)->toBe(2)
        ->and($data->pendingCount)->toBe(1)
        ->and($data->expiredCount)->toBe(1)
        ->and($data->sparklinePublished)->toHaveCount(7);
});
