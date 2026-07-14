<?php

declare(strict_types=1);

use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;

it('reports the publish visibility state for each page shape', function (): void {
    $publishedPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);
    $scheduledPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addWeek(),
        'visible_until' => null,
    ]);
    $draftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);
    $expiredPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subMonth(),
        'visible_until' => CarbonImmutable::now()->subDay(),
    ]);
    $deletedPage = Page::factory()->createOne();
    $deletedPage->delete();

    expect($publishedPage->publishVisibilityState())->toBe(PublishVisibilityStateEnum::published)
        ->and($scheduledPage->publishVisibilityState())->toBe(PublishVisibilityStateEnum::scheduled)
        ->and($draftPage->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft)
        ->and($expiredPage->publishVisibilityState())->toBe(PublishVisibilityStateEnum::expired)
        ->and($deletedPage->refresh()->publishVisibilityState())->toBe(PublishVisibilityStateEnum::deleted);
});

it('detects the draft sentinel on the model', function (): void {
    $draftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);
    $scheduledPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addWeek(),
        'visible_until' => null,
    ]);
    $publishedPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);

    expect($draftPage->isDraftSentinel())->toBeTrue()
        ->and($scheduledPage->isDraftSentinel())->toBeFalse()
        ->and($publishedPage->isDraftSentinel())->toBeFalse();
});

it('partitions pages with the draftSentinel and scheduled scopes', function (): void {
    $publishedPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);
    $scheduledPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addWeek(),
        'visible_until' => null,
    ]);
    $draftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);

    $draftIds = Page::query()->draftSentinel()->pluck('id');
    $scheduledIds = Page::query()->scheduled()->pluck('id');

    expect($draftIds->all())->toBe([$draftPage->id])
        ->and($scheduledIds->all())->toBe([$scheduledPage->id])
        ->and($draftIds)->not->toContain($publishedPage->id)
        ->and($scheduledIds)->not->toContain($publishedPage->id);
});

it('keeps isPending and the pending scope as an umbrella over drafts and schedules', function (): void {
    $publishedPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);
    $scheduledPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addWeek(),
        'visible_until' => null,
    ]);
    $draftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);

    $pendingIds = Page::query()->pending()->pluck('id');

    expect($draftPage->isPending())->toBeTrue()
        ->and($scheduledPage->isPending())->toBeTrue()
        ->and($publishedPage->isPending())->toBeFalse()
        ->and($pendingIds->all())->toEqualCanonicalizing([$scheduledPage->id, $draftPage->id]);
});

it('keeps publishedDate excluding drafts, schedules and expired pages', function (): void {
    $publishedPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);
    $scheduledPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addWeek(),
        'visible_until' => null,
    ]);
    $draftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);
    $expiredPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->subMonth(),
        'visible_until' => CarbonImmutable::now()->subDay(),
    ]);

    $publishedIds = Page::query()->publishedDate()->pluck('id');

    expect($publishedIds->all())->toBe([$publishedPage->id])
        ->and($publishedIds)->not->toContain($scheduledPage->id, $draftPage->id, $expiredPage->id);
});

it('keeps the publish status attribute collapsing drafts and schedules to pending', function (): void {
    $scheduledPage = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addWeek(),
        'visible_until' => null,
    ]);
    $draftPage = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'visible_until' => null,
    ]);

    expect($scheduledPage->getPublishStatus())->toBe(PublishStatusEnum::pending)
        ->and($draftPage->getPublishStatus())->toBe(PublishStatusEnum::pending);
});
