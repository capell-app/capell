<?php

declare(strict_types=1);

use Capell\Core\Actions\Interactions\ResolveInteractionTriggersAction;
use Capell\Core\Enums\InteractionBehavior;
use Capell\Core\Enums\InteractionTargetType;

it('normalizes widget interaction targets from nested widget builder state', function (): void {
    $triggers = ResolveInteractionTriggersAction::run([
        [
            'label' => 'Play video',
            'icon' => 'heroicon-o-play',
            'behavior' => 'modal',
            'target_type' => 'widget',
            'target_widget' => [
                ['type' => 'content', 'data' => ['content' => '<p>Video embed</p>']],
            ],
        ],
    ]);

    expect($triggers)->toHaveCount(1)
        ->and($triggers[0]->label)->toBe('Play video')
        ->and($triggers[0]->behavior)->toBe(InteractionBehavior::Modal)
        ->and($triggers[0]->target->type)->toBe(InteractionTargetType::Widget)
        ->and($triggers[0]->target->widgetType)->toBe('content')
        ->and($triggers[0]->target->widgetData)->toBe(['content' => '<p>Video embed</p>']);
});

it('drops invalid interaction targets instead of rendering broken public controls', function (): void {
    $triggers = ResolveInteractionTriggersAction::run([
        ['label' => 'Missing widget', 'target_type' => 'widget'],
        ['label' => 'Unsafe URL', 'target_type' => 'url', 'url' => 'javascript:alert(1)'],
        ['target_type' => 'widget', 'widget_type' => 'content'],
    ]);

    expect($triggers)->toBe([]);
});
