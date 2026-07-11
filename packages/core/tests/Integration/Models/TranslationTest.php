<?php

declare(strict_types=1);

use Capell\Core\Contracts\Media\MediaContract as Media;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

it('belongs to a language', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();
    $translation = Translation::factory()
        ->translatable($page)
        ->create(['language_id' => $language->id]);

    expect($translation->language)
        ->toBeInstanceOf(Language::class)
        ->and($translation->language->id)->toBe($language->id);
});

it('returns null for language when language is deleted', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();
    $translation = Translation::factory()
        ->translatable($page)
        ->create(['language_id' => $language->id]);
    $language->delete();

    expect($translation->fresh()->language)->toBeNull();
});

it('returns the provided default meta value when the model has no type relation', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()
        ->translatable($page)
        ->create([
            'meta' => null,
        ]);

    expect($translation->getMeta('seo.title', 'Fallback title'))->toBe('Fallback title');
});

it('has a morph to translatable relation', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()
        ->state([
            'translatable_type' => $page->getMorphClass(),
            'translatable_id' => $page->id,
        ])
        ->create();

    expect($translation->translatable)
        ->toBeInstanceOf(Page::class)
        ->and($translation->translatable->id)->toBe($page->id);
});

it('handles morph to with site translatable', function (): void {
    $site = Site::factory()->createOne();
    $translation = Translation::factory()
        ->state([
            'translatable_type' => $site->getMorphClass(),
            'translatable_id' => $site->id,
        ])
        ->create();

    expect($translation->translatable)
        ->toBeInstanceOf(Site::class)
        ->and($translation->translatable->id)->toBe($site->id);
});

it('has morph one image media relation', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->create();
    $media = $translation->addMediaFromString('test image content')
        ->usingFileName('test-image.jpg')
        ->toMediaCollection(MediaCollectionEnum::Image->value);

    expect($translation->image)
        ->toBeInstanceOf(Media::class)
        ->and($translation->image->id)->toBe($media->id)
        ->and($translation->image->collection_name)->toBe(MediaCollectionEnum::Image->value);
});

it('returns null for image when no media is attached', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->create();

    expect($translation->image)->toBeNull();
});

it('has morph one background image media relation', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->create();
    $media = $translation->addMediaFromString('test background image content')
        ->usingFileName('test-background-image.jpg')
        ->toMediaCollection(MediaCollectionEnum::BackgroundImage->value);

    expect($translation->backgroundImage)
        ->toBeInstanceOf(Media::class)
        ->and($translation->backgroundImage->id)->toBe($media->id)
        ->and($translation->backgroundImage->collection_name)->toBe(MediaCollectionEnum::BackgroundImage->value);
});

it('returns null for background image when no media is attached', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->create();

    expect($translation->backgroundImage)->toBeNull();
});

it('belongs to a page url using composite key', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $page = Page::factory()->for($site)->create();
    $pageUrl = PageUrl::factory()->createOne([
        'language_id' => $language->id,
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->id,
        'site_id' => $site->id,
    ]);

    $translation = Translation::factory()
        ->state([
            'language_id' => $language->id,
            'translatable_type' => $page->getMorphClass(),
            'translatable_id' => $page->id,
        ])
        ->create();

    expect($translation->pageUrl)
        ->toBeInstanceOf(PageUrl::class)
        ->and($translation->pageUrl->id)->toBe($pageUrl->id)
        ->and($translation->pageUrl->language_id)->toBe($language->id);
});

it('returns null for page url when no matching page url exists', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->create();

    PageUrl::query()->where('pageable_id', $page->id)->delete();

    expect($translation->pageUrl)->toBeNull();
});

it('can have both image and background image', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->create();

    $translation->addMediaFromString('test image content')
        ->usingFileName('test-image.jpg')
        ->toMediaCollection(MediaCollectionEnum::Image->value);

    $translation->addMediaFromString('test background image content')
        ->usingFileName('test-background-image.jpg')
        ->toMediaCollection(MediaCollectionEnum::BackgroundImage->value);

    expect($translation->media)->toHaveCount(2)
        ->and($translation->getMedia(MediaCollectionEnum::Image->value))->toHaveCount(1)
        ->and($translation->getMedia(MediaCollectionEnum::BackgroundImage->value))->toHaveCount(1)
        ->and($translation->getMedia(MediaCollectionEnum::Image->value)->first()->collection_name)
        ->toBe(MediaCollectionEnum::Image->value)
        ->and($translation->getMedia(MediaCollectionEnum::BackgroundImage->value)->first()->collection_name)
        ->toBe(MediaCollectionEnum::BackgroundImage->value);
});

it('maintains separate media collections for image and background image', function (): void {
    $page = Page::factory()->createOne();
    $translation = Translation::factory()->translatable($page)->create();
    $translation->addMediaFromString('test image content')
        ->usingFileName('test-image.jpg')
        ->toMediaCollection(MediaCollectionEnum::Image->value);

    expect($translation->media)->toHaveCount(1)
        ->and($translation->getMedia(MediaCollectionEnum::Image->value))->toHaveCount(1)
        ->and($translation->getMedia(MediaCollectionEnum::BackgroundImage->value))->toHaveCount(0);
});
