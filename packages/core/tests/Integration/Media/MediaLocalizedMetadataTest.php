<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

it('resolves localized media metadata from the shared translations table', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $page = Page::factory()->createOne();
    $media = $page
        ->addMedia(UploadedFile::fake()->image('hero.jpg', 1200, 800))
        ->toMediaCollection('image');

    Translation::query()->create([
        'language_id' => $english->getKey(),
        'translatable_type' => $media->getMorphClass(),
        'translatable_id' => $media->getKey(),
        'title' => 'Hero',
        'meta' => [
            'alt' => 'Family playing outside',
            'caption' => 'Summer launch image',
            'credit' => 'Capell Studio',
        ],
    ]);

    Translation::query()->create([
        'language_id' => $french->getKey(),
        'translatable_type' => $media->getMorphClass(),
        'translatable_id' => $media->getKey(),
        'title' => 'Hero FR',
        'meta' => [
            'alt' => 'Famille jouant dehors',
            'decorative' => true,
        ],
    ]);

    expect($media->getAltText($english))
        ->toBe('Family playing outside')
        ->and($media->getCaption('en'))
        ->toBe('Summer launch image')
        ->and($media->getCredit($english->getKey()))
        ->toBe('Capell Studio')
        ->and($media->isDecorative('fr'))
        ->toBeTrue()
        ->and($media->getAltText('fr'))
        ->toBe('')
        ->and($media->localizedMetadata()->toArray())
        ->toMatchArray([
            'language_id' => $english->getKey(),
            'title' => 'Hero',
            'alt' => 'Family playing outside',
        ]);
});

it('resolves preloaded media translations by language object id code locale and default fallback', function (): void {
    $english = Language::factory()->english()->create(['default' => false, 'order' => 2]);
    $french = Language::factory()->french()->create(['default' => true, 'locale' => 'fr_CA', 'order' => 1]);
    $page = Page::factory()->createOne();
    $media = $page
        ->addMedia(UploadedFile::fake()->image('gallery.jpg', 800, 600))
        ->toMediaCollection('image');

    Translation::query()->create([
        'language_id' => $english->getKey(),
        'translatable_type' => $media->getMorphClass(),
        'translatable_id' => $media->getKey(),
        'title' => 'Gallery',
        'meta' => [
            'alt' => 'English gallery',
        ],
    ]);

    Translation::query()->create([
        'language_id' => $french->getKey(),
        'translatable_type' => $media->getMorphClass(),
        'translatable_id' => $media->getKey(),
        'title' => 'Galerie',
        'meta' => [
            'alt' => 'Galerie francaise',
            'caption' => 'Image localisee',
        ],
    ]);

    $media->load('translations.language');

    expect($media->getAltText($english))->toBe('English gallery')
        ->and($media->getCaption('fr'))->toBe('Image localisee')
        ->and($media->getAltText('fr_CA'))->toBe('Galerie francaise')
        ->and($media->localizedMetadata()->title)->toBe('Galerie');
});

it('falls back to the first ordered translation when no default language metadata exists', function (): void {
    $english = Language::factory()->english()->create(['default' => false, 'order' => 2]);
    $french = Language::factory()->french()->create(['default' => false, 'order' => 1]);
    $page = Page::factory()->createOne();
    $media = $page
        ->addMedia(UploadedFile::fake()->image('fallback.jpg', 800, 600))
        ->toMediaCollection('image');

    Translation::query()->create([
        'language_id' => $english->getKey(),
        'translatable_type' => $media->getMorphClass(),
        'translatable_id' => $media->getKey(),
        'title' => 'English fallback',
        'meta' => [
            'alt' => 'English fallback alt',
        ],
    ]);

    Translation::query()->create([
        'language_id' => $french->getKey(),
        'translatable_type' => $media->getMorphClass(),
        'translatable_id' => $media->getKey(),
        'title' => 'French fallback',
        'meta' => [
            'alt' => 'French fallback alt',
        ],
    ]);

    $media->load('translations.language');

    expect($media->localizedMetadata()->title)->toBe('French fallback')
        ->and($media->getAltText())->toBe('French fallback alt');
});
