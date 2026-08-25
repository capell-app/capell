<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('requires populated page history and reversible recovery proof', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(File::get($root . '/docs/screenshots.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = collect($manifest['entries'])->keyBy('id');

    foreach (['page-history-timeline', 'page-history-rollback-preview'] as $id) {
        $entry = $entries->get($id);

        expect($entry)->not->toBeNull()
            ->and($entry['required'])->toBeTrue()
            ->and($entry['url'])->toBe('/screenshot-fixtures/page-history')
            ->and($root . '/../../' . $entry['output'])->toBeFile()
            ->and(filesize($root . '/../../' . $entry['output']))->toBeGreaterThan(10_000)
            ->and(strtolower((string) $entry['notes']))->not->toContain('optional', 'empty state', 'fixture');
    }

    $historyTabInteraction = [
        'type' => 'click',
        'selector' => "button[wire\\:click*=\"activeRelationManager\"][wire\\:click*=\"'1'\"]",
    ];

    expect($entries->get('page-history-timeline')['beforeWait'])->toBe([
        $historyTabInteraction,
    ])->and($entries->get('page-history-timeline')['interactions'])->toBe([
        [
            'type' => 'waitFor',
            'selector' => '.fi-ta',
        ],
        [
            'type' => 'scrollIntoView',
            'selector' => '.fi-ta',
        ],
    ])->and($entries->get('page-history-rollback-preview')['beforeWait'])->toBe([
        $historyTabInteraction,
        [
            'type' => 'click',
            'selector' => "button[wire\\:click*='rollback']",
        ],
    ]);

    $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);

    // The double-underscore-then-paren helper call opener is written as a
    // concatenation below because scripts/audit-language-keys.sh flags that
    // literal sequence, unquoted, as a dynamic capell- translation site
    // regardless of surrounding context.
    expect($encoded)
        ->not->toMatch('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i')
        ->not->toContain('http://localhost', 'https://localhost', '__' . '(', '{{', 'translation missing');
});

it('requires the theme customize capture to open the installed Foundation theme editor', function (): void {
    $root = dirname(__DIR__, 4);
    $manifest = json_decode(File::get($root . '/docs/screenshots.json'), true, flags: JSON_THROW_ON_ERROR);
    $entry = collect($manifest['entries'])->keyBy('id')->get('theme-customize-preview-apply');

    expect($entry)->not->toBeNull()
        ->and($entry['required'])->toBeTrue()
        ->and($entry['url'])->toBe('/themes')
        ->and($entry['beforeWait'])->toBe([
            [
                'type' => 'click',
                'selector' => ".capell-theme-card-record:has(h3:has-text('Foundation')) button[aria-label='Customize']",
            ],
        ])
        ->and($entry['interactions'])->toBe([
            [
                'type' => 'waitFor',
                'selector' => ".fi-modal-window:not([style*='display: none']) .fi-modal-heading:has-text('Edit Foundation')",
            ],
        ]);
});
