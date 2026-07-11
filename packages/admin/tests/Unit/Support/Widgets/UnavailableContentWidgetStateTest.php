<?php

declare(strict_types=1);

use Capell\Admin\Support\Widgets\UnavailableContentWidgetState;

it('round trips unavailable top-level widgets without exposing their data as editable fields', function (): void {
    $unknown = [
        'type' => 'missing.vendor-widget',
        'data' => ['secret-shape' => ['anything' => true]],
        'extra' => 'preserve me',
    ];
    $available = ['type' => 'content', 'data' => ['content' => 'Known']];

    $authoringState = UnavailableContentWidgetState::prepare(
        ['unknown-key' => $unknown, 'known-key' => $available],
        ['content'],
    );

    expect($authoringState['unknown-key']['type'])->toBe(UnavailableContentWidgetState::PLACEHOLDER_TYPE)
        ->and($authoringState['unknown-key']['data'])->toHaveKey(UnavailableContentWidgetState::OPAQUE_STATE_KEY)
        ->and($authoringState['known-key'])->toBe($available)
        ->and(UnavailableContentWidgetState::restore($authoringState, ['content']))->toBe([
            'unknown-key' => $unknown,
            'known-key' => $available,
        ]);
});

it('wraps and restores nested unavailable widgets without inspecting their opaque payloads', function (): void {
    $unknown = [
        'type' => 'missing.nested-widget',
        'data' => [
            'nested_registered_shape' => ['type' => 'content', 'data' => ['content' => 'opaque']],
        ],
        'extra' => true,
    ];
    $state = [[
        'type' => 'content',
        'data' => ['interaction' => ['target_widget' => $unknown]],
    ]];

    $prepared = UnavailableContentWidgetState::prepare($state, ['content']);
    $placeholder = $prepared[0]['data']['interaction']['target_widget'];

    expect($placeholder['type'])->toBe(UnavailableContentWidgetState::PLACEHOLDER_TYPE)
        ->and($placeholder['data'][UnavailableContentWidgetState::OPAQUE_STATE_KEY])->toBe($unknown)
        ->and(UnavailableContentWidgetState::restore($prepared, ['content']))->toBe($state);
});

it('does not mistake typed rich text document nodes for unavailable widgets', function (): void {
    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Preserved']],
        ]],
    ];
    $state = [['type' => 'content', 'data' => ['content' => $document]]];

    expect(UnavailableContentWidgetState::prepare($state, ['content']))->toBe($state);
});

it('does not traverse an unavailable widget opaque payload when enforcing bounds', function (): void {
    $deepPayload = ['value' => 'leaf'];

    for ($depth = 0; $depth < 80; $depth++) {
        $deepPayload = ['next' => $deepPayload];
    }

    $unknown = ['type' => 'missing.deep-widget', 'data' => $deepPayload];
    $prepared = UnavailableContentWidgetState::prepare([$unknown], ['content']);

    expect($prepared[0]['type'])->toBe(UnavailableContentWidgetState::PLACEHOLDER_TYPE)
        ->and(UnavailableContentWidgetState::restore($prepared, ['content']))->toBe([$unknown]);
});

it('leaves over-wide generic state untouched rather than partially wrapping it', function (): void {
    $wideState = [];

    for ($index = 0; $index <= 10_000; $index++) {
        $wideState[$index] = ['value' => $index];
    }

    expect(UnavailableContentWidgetState::prepare($wideState, ['content']))->toBe($wideState);
});
