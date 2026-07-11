<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;

it('belongs to a type', function (): void {
    $type = Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Theme]);
    $theme = Theme::factory()->createOne(['blueprint_id' => $type->id]);

    expect($theme->blueprint)->toBeInstanceOf(Blueprint::class)
        ->and($theme->blueprint->id)->toBe($type->id);
});

it('has many layouts', function (): void {
    $theme = Theme::factory()->createOne();
    $layout = Layout::factory()->createOne(['theme_id' => $theme->id]);

    expect($theme->layouts->pluck('id'))->toContain($layout->id);
});

it('has many sites', function (): void {
    $theme = Theme::factory()->createOne();
    $site = Site::factory()->createOne(['theme_id' => $theme->id]);

    expect($theme->sites->pluck('id'))->toContain($site->id);
});

it('can scope sorted', function (): void {
    Theme::factory()->createOne(['name' => 'B', 'default' => false, 'order' => 2]);
    Theme::factory()->createOne(['name' => 'A', 'default' => true, 'order' => 1]);
    Theme::factory()->createOne(['name' => 'C', 'default' => false, 'order' => 3]);

    $result = Theme::query()->ordered()->pluck('name')->all();

    expect($result)->toBe(['A', 'B', 'C']);
});
