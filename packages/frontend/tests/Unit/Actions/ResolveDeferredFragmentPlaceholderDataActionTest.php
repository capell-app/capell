<?php

declare(strict_types=1);

use Capell\Frontend\Actions\ResolveDeferredFragmentPlaceholderDataAction;

it('returns null when a fragment is not deferred', function (): void {
    expect(ResolveDeferredFragmentPlaceholderDataAction::run([], ['id' => 1], '/fragment'))->toBeNull();
});

it('builds deferred fragment placeholder data from safe public metadata', function (): void {
    $placeholder = ResolveDeferredFragmentPlaceholderDataAction::run(
        meta: [
            'performance' => [
                'defer' => true,
                'defer_strategy' => 'idle',
                'defer_min_height' => '18rem',
            ],
        ],
        reference: ['id' => 1, 'updatedAt' => 123],
        url: '/fragment/reference',
    );

    expect($placeholder)
        ->not->toBeNull()
        ->cacheKey->toBeString()
        ->url->toBe('/fragment/reference')
        ->strategy->toBe('idle')
        ->minHeight->toBe('18rem')
        ->variant->toBe('band');
});

it('resolves an allowlisted skeleton variant', function (): void {
    $placeholder = ResolveDeferredFragmentPlaceholderDataAction::run(
        meta: [
            'performance' => [
                'defer' => true,
                'defer_skeleton' => 'gallery',
            ],
        ],
        reference: ['id' => 1],
        url: '/fragment/reference',
    );

    expect($placeholder)
        ->not->toBeNull()
        ->variant->toBe('gallery');
});

it('normalises unsafe deferred placeholder settings', function (): void {
    $placeholder = ResolveDeferredFragmentPlaceholderDataAction::run(
        meta: [
            'performance' => [
                'defer' => true,
                'defer_strategy' => 'eager',
                'defer_min_height' => 'expression(alert(1))',
                'defer_skeleton' => 'carousel',
            ],
        ],
        reference: ['id' => 1],
        url: '/fragment/reference',
    );

    expect($placeholder)
        ->not->toBeNull()
        ->strategy->toBe('visible')
        ->minHeight->toBeNull()
        ->variant->toBe('band');
});
