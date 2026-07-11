<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Frontend\Support\View\PublicModelMeta;

it('keeps explicit falsy meta values instead of falling back to blueprint defaults', function (): void {
    $type = Blueprint::factory()->theme()->create([
        'meta' => [
            'rounded_images' => true,
            'list_bullets' => true,
        ],
    ]);
    $theme = Theme::factory()
        ->for($type, 'type')
        ->create([
            'meta' => [
                'rounded_images' => false,
                'list_bullets' => '0',
            ],
        ])
        ->load('type');

    expect(PublicModelMeta::get($theme, 'rounded_images', true))->toBeFalse()
        ->and(PublicModelMeta::get($theme, 'list_bullets', true))->toBe('0');
});
