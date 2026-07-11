<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Feature;

use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Support\Facades\Blade;

it('uses localized media alt text for rendered frontend images', function (): void {
    $language = Language::factory()->english()->create();
    $media = Media::factory()->image()->create([
        'model_type' => 'frontend-media-test',
        'model_id' => 1,
        'name' => 'Fallback image name',
        'custom_properties' => [
            'width' => 640,
            'height' => 360,
        ],
    ]);

    Translation::factory()
        ->language($language)
        ->for($media, 'translatable')
        ->create([
            'title' => 'Editorial image title',
            'meta' => [
                'alt' => 'Localized editorial alt text',
                'caption' => 'Localized caption',
            ],
        ]);

    resolve(FrontendState::class)
        ->withLanguage($language)
        ->withTheme(Theme::factory()->defaultMeta()->create());

    $html = Blade::render(
        '<x-capell::media :media="$media" />',
        ['media' => $media],
    );

    expect($html)
        ->toContain('alt="Localized editorial alt text"')
        ->not->toContain('alt="Fallback image name"');
});

it('renders an empty alt attribute for decorative media', function (): void {
    $language = Language::factory()->english()->create();
    $media = Media::factory()->image()->create([
        'model_type' => 'frontend-media-test',
        'model_id' => 1,
        'name' => 'Decorative fallback name',
        'custom_properties' => [
            'width' => 640,
            'height' => 360,
        ],
    ]);

    Translation::factory()
        ->language($language)
        ->for($media, 'translatable')
        ->create([
            'meta' => [
                'alt' => 'Ignored decorative alt',
                'decorative' => true,
            ],
        ]);

    resolve(FrontendState::class)
        ->withLanguage($language)
        ->withTheme(Theme::factory()->defaultMeta()->create());

    $html = Blade::render(
        '<x-capell::media :media="$media" />',
        ['media' => $media],
    );

    expect($html)
        ->toContain('alt=""')
        ->not->toContain('Ignored decorative alt');
});
