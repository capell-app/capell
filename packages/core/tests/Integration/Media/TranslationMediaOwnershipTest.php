<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * @return array{0: Page, 1: Translation, 2: Translation}
 */
function sharedMediaPage(): array
{
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $page = Page::factory()->createOne();
    $en = Translation::factory()->translatable($page)->language($english)->create(['title' => 'Logos']);
    $fr = Translation::factory()->translatable($page)->language($french)->create(['title' => 'Logos FR']);

    return [$page, $en, $fr];
}

function translationOwnedMedia(Translation $translation, string $file, string $collection, ?string $alt = null): Media
{
    $media = $translation->addMedia(UploadedFile::fake()->image($file, 40, 20))->preservingOriginal()->toMediaCollection($collection);
    throw_unless($media instanceof Media, RuntimeException::class, 'Expected Capell media.');

    if ($alt !== null) {
        Translation::query()->create([
            'language_id' => $translation->language_id,
            'translatable_type' => $media->getMorphClass(),
            'translatable_id' => $media->getKey(),
            'meta' => ['alt' => $alt],
        ]);
    }

    return $media;
}

function runShareTranslationMediaMigration(): void
{
    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_11_14_000001_share_translation_media_with_translatable_owner.php';
    $migration->up();
}

it("resolves a translation's media owner to its translatable record when that record holds media", function (): void {
    [$page, $en] = sharedMediaPage();
    $mediaTranslation = Translation::factory()->translatable(Media::factory()->model($page)->create())->create();

    expect($en->mediaOwner()->is($page))->toBeTrue()
        ->and($mediaTranslation->mediaOwner()->is($mediaTranslation))->toBeTrue();
});

it('lets every language of a page use one media item with its own alt text', function (): void {
    [, $en, $fr] = sharedMediaPage();
    $media = $en->mediaOwner()->addMedia(UploadedFile::fake()->image('hero.jpg', 40, 20))->toMediaCollection('builder-block-1');
    throw_unless($media instanceof Media, RuntimeException::class, 'Expected Capell media.');
    foreach ([[$en, 'A heron'], [$fr, 'Un héron']] as [$translation, $alt]) {
        Translation::query()->create(['language_id' => $translation->language_id, 'translatable_type' => $media->getMorphClass(), 'translatable_id' => $media->getKey(), 'meta' => ['alt' => $alt]]);
    }

    expect($fr->mediaOwner()->getMedia('builder-block-1')->pluck('uuid')->all())->toBe([$media->uuid])
        ->and($media->localizedMetadata($en->language_id)->alt)->toBe('A heron')
        ->and($media->localizedMetadata($fr->language_id)->alt)->toBe('Un héron');
});

it('moves translation-owned media to the page and merges copies made for another language', function (): void {
    [$page, $en, $fr] = sharedMediaPage();
    $collection = 'builder-block-0f0c6b7e-1111-4a5b-9c1d-2e3f4a5b6c7d';
    $enLogo = translationOwnedMedia($en, 'logo.png', $collection, 'Logo');
    $frCopy = translationOwnedMedia($fr, 'logo.png', $collection, 'Le logo');
    $frOnly = translationOwnedMedia($fr, 'partenaire.png', $collection);
    $en->forceFill(['content' => [['type' => 'logos', 'data' => ['media_key' => '0f0c6b7e-1111-4a5b-9c1d-2e3f4a5b6c7d', 'items' => [['image' => $enLogo->uuid]]]]]])->save();
    $fr->forceFill(['content' => [['type' => 'logos', 'data' => ['media_key' => '0f0c6b7e-1111-4a5b-9c1d-2e3f4a5b6c7d', 'items' => [['image' => $frCopy->uuid], ['image' => $frOnly->uuid]]]]]])->save();

    runShareTranslationMediaMigration();

    $owned = $page->refresh()->getMedia($collection);
    $storedFrench = (string) DB::table($fr->getTable())->where('id', $fr->getKey())->value('content');

    expect($owned->pluck('id')->sort()->values()->all())->toBe([$enLogo->getKey(), $frOnly->getKey()])
        ->and(Media::query()->withTrashed()->whereKey($frCopy->getKey())->firstOrFail()->trashed())->toBeTrue()
        ->and($storedFrench)->toContain($enLogo->uuid)->toContain($frOnly->uuid)->not->toContain($frCopy->uuid)
        ->and($enLogo->refresh()->localizedMetadata($fr->language_id)->alt)->toBe('Le logo')
        ->and($enLogo->localizedMetadata($en->language_id)->alt)->toBe('Logo')
        ->and($en->refresh()->getMedia($collection))->toHaveCount(0);
});
