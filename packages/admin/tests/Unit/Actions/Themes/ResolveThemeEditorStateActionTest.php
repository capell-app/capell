<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\ResolveThemeEditorStateAction;
use Capell\Core\Models\Theme;

uses()->group('theme');

it('uses fresh editor defaults when a theme has no clean editor state', function (): void {
    $theme = Theme::factory()->createOne([
        'meta' => [
            'active_preset' => 'legacy',
            'assets' => ['resources/css/legacy.css'],
        ],
        'admin' => [
            'description' => 'Legacy description',
        ],
    ]);

    $state = ResolveThemeEditorStateAction::run($theme);

    expect($state->preset['active'])->toBe('default')
        ->and($state->assets['paths'])->toBe([])
        ->and($state->admin['description'])->toBeNull();
});

it('resolves clean editor state from meta and admin editor keys', function (): void {
    $theme = Theme::factory()->createOne([
        'meta' => [
            'editor' => [
                'preset' => ['active' => 'launch'],
                'brand' => ['primaryColor' => '#2563eb'],
                'assets' => ['paths' => ['resources/css/theme.css']],
            ],
        ],
        'admin' => [
            'editor' => [
                'description' => 'Clean description',
            ],
        ],
    ]);

    $state = ResolveThemeEditorStateAction::run($theme);

    expect($state->preset['active'])->toBe('launch')
        ->and($state->brand['primaryColor'])->toBe('#2563eb')
        ->and($state->assets['paths'])->toBe(['resources/css/theme.css'])
        ->and($state->admin['description'])->toBe('Clean description');
});
