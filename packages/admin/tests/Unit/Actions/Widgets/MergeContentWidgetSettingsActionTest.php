<?php

declare(strict_types=1);

use Capell\Admin\Actions\Widgets\MergeContentWidgetSettingsAction;

it('updates modal-owned settings while retaining reserved and future metadata', function (): void {
    $widgetData = [
        'title' => 'Widget',
        '__capell' => [
            'instance_id' => '0198bd52-14b0-71df-9b18-387cd9e2d5a4',
            'state_version' => 3,
            'future_metadata' => ['owner' => 'extension'],
            'presentation' => ['width' => 'old'],
            'interactions' => [['event' => 'old']],
            'resources' => ['strategy' => 'old'],
        ],
    ];

    $merged = MergeContentWidgetSettingsAction::run($widgetData, [
        'presentation' => ['width' => 'wide', 'alignment' => ''],
        'interactions' => [['event' => 'click']],
        'resources' => ['strategy' => 'visible'],
        'unowned' => ['must' => 'not be stored'],
    ]);

    expect($merged['title'])->toBe('Widget')
        ->and($merged['__capell'])->toBe([
            'instance_id' => '0198bd52-14b0-71df-9b18-387cd9e2d5a4',
            'state_version' => 3,
            'future_metadata' => ['owner' => 'extension'],
            'interactions' => [['event' => 'click']],
            'presentation' => ['width' => 'wide'],
            'resources' => ['strategy' => 'visible'],
        ]);
});

it('clears only modal-owned settings while retaining reserved and future metadata', function (): void {
    $merged = MergeContentWidgetSettingsAction::run([
        '__capell' => [
            'instance_id' => '0198bd52-14b0-71df-9b18-387cd9e2d5a4',
            'state_version' => 3,
            'future_metadata' => ['empty' => ''],
            'presentation' => ['width' => 'wide'],
            'interactions' => [['event' => 'click']],
            'resources' => ['strategy' => 'visible'],
        ],
    ], [
        'presentation' => [],
        'interactions' => [],
        'resources' => [],
    ]);

    expect($merged['__capell'])->toBe([
        'instance_id' => '0198bd52-14b0-71df-9b18-387cd9e2d5a4',
        'state_version' => 3,
        'future_metadata' => ['empty' => ''],
    ]);
});
