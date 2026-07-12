<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Feature;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Support\Facades\Blade;
use Mockery;

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
        ->toContain('loading="lazy"')
        ->not->toContain('alt="Fallback image name"');
});

it('eagerly loads only media identified as the lcp candidate by its canonical url', function (): void {
    $language = Language::factory()->english()->create();
    $media = Mockery::mock(MediaContract::class);
    $media->shouldReceive('getAvailableFullUrl')
        ->once()
        ->andReturn('https://example.test/storage/conversions/hero-medium.webp');
    $media->shouldReceive('hasResponsiveImages')->once()->andReturnFalse();
    $media->shouldReceive('hasConversion')->times(4)->andReturnFalse();
    $media->shouldReceive('getFullUrl')
        ->once()
        ->withNoArgs()
        ->andReturn('https://example.test/storage/hero.jpg');
    $media->shouldReceive('getName')->once()->andReturn('Hero image');

    resolve(FrontendState::class)
        ->withLanguage($language)
        ->withTheme(Theme::factory()->defaultMeta()->create())
        ->setFrontendData('lcpMediaUrl', 'https://example.test/storage/hero.jpg');

    $html = Blade::render(
        '<x-capell::media :media="$media" width="640" height="360" />',
        ['media' => $media],
    );

    expect($html)
        ->toContain('loading="eager"')
        ->toContain('fetchpriority="high"');
});

it('preserves an explicit eager loading choice for non lcp media', function (): void {
    $language = Language::factory()->english()->create();
    $media = Media::factory()->image()->create([
        'model_type' => 'frontend-media-test',
        'model_id' => 1,
        'name' => 'Above fold image',
        'custom_properties' => [
            'width' => 640,
            'height' => 360,
        ],
    ]);

    resolve(FrontendState::class)
        ->withLanguage($language)
        ->withTheme(Theme::factory()->defaultMeta()->create());

    $html = Blade::render(
        '<x-capell::media :media="$media" loading="eager" />',
        ['media' => $media],
    );

    expect($html)
        ->toContain('loading="eager"')
        ->not->toContain('fetchpriority="high"');
});

it('does not promote every content component image to lcp priority', function (): void {
    $language = Language::factory()->english()->create();
    $media = Media::factory()->image()->create([
        'model_type' => 'frontend-media-test',
        'model_id' => 1,
        'name' => 'Supporting content image',
        'custom_properties' => [
            'width' => 640,
            'height' => 360,
        ],
    ]);

    resolve(FrontendState::class)
        ->withLanguage($language)
        ->withTheme(Theme::factory()->defaultMeta()->create());

    $html = Blade::render(
        '<x-capell::content :image="$media" title="Supporting content" />',
        ['media' => $media],
    );

    expect($html)
        ->toContain('loading="lazy"')
        ->not->toContain('fetchpriority="high"');
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
