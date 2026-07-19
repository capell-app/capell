<?php

declare(strict_types=1);

use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;

it('writes a draft value beyond the boundary', function (): void {
    expect(PagePublishSentinel::draftValue()->greaterThan(PagePublishSentinel::draftBoundary()))->toBeTrue()
        ->and(PagePublishSentinel::isDraftValue(PagePublishSentinel::draftValue()))->toBeTrue();
});

it('delegates the boundary to the core publishing sentinel', function (): void {
    $expected = CarbonImmutable::now()->addYears(PublishSentinel::DRAFT_BOUNDARY_YEARS);

    expect(PagePublishSentinel::draftBoundary()->diffInDays($expected, true))->toBeLessThan(1.0);
});

it('treats a near-future schedule date as a real schedule, not a draft', function (): void {
    expect(PagePublishSentinel::isDraftValue(CarbonImmutable::now()->addWeek()))->toBeFalse()
        ->and(PagePublishSentinel::isDraftValue(CarbonImmutable::now()->subDay()))->toBeFalse()
        ->and(PagePublishSentinel::isDraftValue(null))->toBeFalse();
});
