<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ResolvePagePublishStateAction;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;

it('treats the far-future draft sentinel as draft, not a scheduled publish', function (): void {
    $page = Page::factory()->create([
        'visible_from' => PagePublishSentinel::draftValue(),
        'visible_until' => null,
    ]);

    $state = ResolvePagePublishStateAction::run($page);

    expect($state->isDraft)->toBeTrue()
        ->and($state->scheduledPublishAt)->toBeNull()
        ->and($state->hasScheduledPublish())->toBeFalse()
        ->and($state->publishedAt)->toBeNull()
        ->and($state->isPublished())->toBeFalse();
});

it('keeps a genuine future schedule as scheduled, not draft', function (): void {
    $publishAt = CarbonImmutable::now()->addWeek();
    $page = Page::factory()->create([
        'visible_from' => $publishAt,
        'visible_until' => null,
    ]);

    $state = ResolvePagePublishStateAction::run($page);

    expect($state->isDraft)->toBeFalse()
        ->and($state->scheduledPublishAt?->toDateString())->toBe($publishAt->toDateString())
        ->and($state->hasScheduledPublish())->toBeTrue();
});

it('keeps a live page published with no draft or schedule flags', function (): void {
    $page = Page::factory()->create([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => null,
    ]);

    $state = ResolvePagePublishStateAction::run($page);

    expect($state->isDraft)->toBeFalse()
        ->and($state->scheduledPublishAt)->toBeNull()
        ->and($state->publishedAt?->toDateString())->toBe(CarbonImmutable::now()->subDay()->toDateString())
        ->and($state->isPublished())->toBeTrue();
});
