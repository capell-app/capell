<?php

declare(strict_types=1);

use Capell\Frontend\Support\Fragments\DeferredFragmentPlaceholderData;
use Capell\Frontend\Support\Fragments\DeferredFragmentReference;

it('encodes and decodes deferred fragment references', function (): void {
    config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);

    $reference = [
        'sectionId' => 123,
        'pageId' => 456,
        'languageId' => 789,
    ];

    expect(DeferredFragmentReference::decode(DeferredFragmentReference::encode($reference)))
        ->toBe($reference)
        ->and(DeferredFragmentReference::cacheKey($reference))
        ->toBe(DeferredFragmentReference::cacheKey(array_reverse($reference, true)));
});

it('returns empty data for invalid deferred fragment references', function (): void {
    expect(DeferredFragmentReference::decode('not-valid'))->toBe([]);
});

it('builds a stable cache key from an opaque reference', function (): void {
    expect(DeferredFragmentReference::cacheKey('opaque-reference'))
        ->toBe(DeferredFragmentReference::cacheKey('opaque-reference'))
        ->not->toBe(DeferredFragmentReference::cacheKey('another-reference'));
});

it('formats optional deferred placeholder min height styles', function (): void {
    expect(new DeferredFragmentPlaceholderData(
        cacheKey: 'fragment-key',
        url: '/fragment',
        strategy: 'visible',
        minHeight: '32rem',
    )->minHeightStyle())->toBe(' style="min-height: 32rem"')
        ->and(new DeferredFragmentPlaceholderData(
            cacheKey: 'fragment-key',
            url: '/fragment',
            strategy: 'visible',
            minHeight: null,
        )->minHeightStyle())->toBe('');
});
