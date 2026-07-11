<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\ResolvePagePublishStateAction;
use Capell\Admin\Data\PagePublishStateData;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;

it('reports published state correctly', function (): void {
    $state = new PagePublishStateData(
        pageId: 1,
        isDraft: false,
        publishedAt: now()->toImmutable(),
        previewUrl: null,
    );

    expect($state->isPublished())->toBeTrue()
        ->and($state->hasActiveContext())->toBeFalse();
});

it('reports draft state inside a context', function (): void {
    $state = new PagePublishStateData(
        pageId: 2,
        isDraft: true,
        publishedAt: null,
        previewUrl: 'https://example.com/preview',
        contextId: 10,
        contextName: 'Sprint 1',
        contextStatus: 'open',
    );

    expect($state->isPublished())->toBeFalse()
        ->and($state->hasActiveContext())->toBeTrue()
        ->and($state->previewUrl)->toBe('https://example.com/preview');
});

it('returns a non-empty status label', function (): void {
    $state = new PagePublishStateData(
        pageId: 3,
        isDraft: false,
        publishedAt: now()->toImmutable(),
        previewUrl: null,
    );

    expect($state->statusLabel())->toBeString()->not()->toBe('');
});

it('reports scheduled publish state', function (): void {
    $state = new PagePublishStateData(
        pageId: 4,
        isDraft: false,
        publishedAt: null,
        previewUrl: null,
        scheduledPublishAt: CarbonImmutable::now()->addDay(),
    );

    expect($state->hasScheduledPublish())->toBeTrue()
        ->and($state->isPublished())->toBeFalse()
        ->and($state->statusLabel())->toBe(__('capell-admin::publish_panel.status_scheduled_publish'));
});

it('reports scheduled unpublish state', function (): void {
    $state = new PagePublishStateData(
        pageId: 5,
        isDraft: false,
        publishedAt: CarbonImmutable::now()->subDay(),
        previewUrl: null,
        unpublishAt: CarbonImmutable::now()->addDay(),
    );

    expect($state->hasScheduledUnpublish())->toBeTrue()
        ->and($state->isExpired())->toBeFalse()
        ->and($state->isPublished())->toBeTrue();
});

it('reports expired unpublished state', function (): void {
    $state = new PagePublishStateData(
        pageId: 6,
        isDraft: false,
        publishedAt: CarbonImmutable::now()->subWeek(),
        previewUrl: null,
        unpublishAt: CarbonImmutable::now()->subDay(),
    );

    expect($state->isExpired())->toBeTrue()
        ->and($state->isPublished())->toBeFalse()
        ->and($state->statusLabel())->toBe(__('capell-admin::publish_panel.status_unpublished'));
});

it('resolves publish state dates from page visibility columns', function (): void {
    $page = Page::factory()->create([
        'visible_from' => CarbonImmutable::now()->subDay(),
        'visible_until' => CarbonImmutable::now()->addWeek(),
    ]);

    $state = ResolvePagePublishStateAction::run($page);

    expect($state->publishedAt?->toDateString())->toBe(CarbonImmutable::now()->subDay()->toDateString())
        ->and($state->scheduledPublishAt)->toBeNull()
        ->and($state->unpublishAt?->toDateString())->toBe(CarbonImmutable::now()->addWeek()->toDateString())
        ->and($state->hasScheduledUnpublish())->toBeTrue();
});
