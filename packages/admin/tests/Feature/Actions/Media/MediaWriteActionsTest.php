<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Actions\Media\UpdateMediaAction;
use Capell\Admin\Actions\Media\UploadSiteMediaAction;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

uses()->group('media');

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config()->set('capell.media.model', Media::class);
    config()->set('media-library.media_model', Media::class);
});

it('creates ordered external video media for a site', function (): void {
    $site = Site::factory()->createOne();
    $video = expectPresent(YouTubeVideoUrl::parse('https://youtu.be/FgalLC99jzY'));

    $first = CreateExternalVideoMediaAction::run($site, 'Product tour', $video);
    $second = CreateExternalVideoMediaAction::run($site, 'Editor tour', $video);

    expect($first->refresh())
        ->collection_name->toBe(MediaCollectionEnum::Video->value)
        ->name->toBe('Product tour')
        ->file_name->toBe('FgalLC99jzY.youtube')
        ->mime_type->toBe('video/youtube')
        ->order_column->toBe(1)
        ->and($first->externalVideo()?->videoId)->toBe('FgalLC99jzY')
        ->and($second->order_column)->toBe(2);
});

it('uploads normalized local paths to the site uploads collection', function (): void {
    $site = Site::factory()->createOne();

    Storage::disk('local')->put('media-uploads/hero-a.jpg', UploadedFile::fake()->image('hero-a.jpg')->getContent());
    Storage::disk('local')->put('media-uploads/hero-b.jpg', UploadedFile::fake()->image('hero-b.jpg')->getContent());

    $count = UploadSiteMediaAction::run($site, [
        ['media-uploads/hero-a.jpg'],
        '',
        'media-uploads/hero-b.jpg',
        null,
    ]);

    $uploads = Media::query()
        ->where('model_type', $site->getMorphClass())
        ->where('model_id', $site->getKey())
        ->where('collection_name', 'uploads')
        ->orderBy('file_name')
        ->get();

    expect($count)->toBe(2)
        ->and($uploads)->toHaveCount(2)
        ->and($uploads->pluck('name')->all())->toBe(['hero-a', 'hero-b']);
});

it('updates image editing state localized metadata and derived files', function (): void {
    $owner = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    /** @var Media $media */
    $media = $owner
        ->addMedia(UploadedFile::fake()->image('hero.jpg', 1200, 800))
        ->usingName('Hero')
        ->toMediaCollection('image');

    $fileManipulator = new class extends FileManipulator
    {
        /** @var list<array{names: array<int, string>, responsive: bool}> */
        public array $calls = [];

        /** @param array<int, string> $onlyConversionNames */
        public function createDerivedFiles(
            SpatieMedia $media,
            array $onlyConversionNames = [],
            bool $onlyMissing = false,
            bool $withResponsiveImages = false,
            bool $queueAll = false,
        ): void {
            $this->calls[] = [
                'names' => $onlyConversionNames,
                'responsive' => $withResponsiveImages,
            ];
        }
    };

    app()->instance(FileManipulator::class, $fileManipulator);

    $updated = UpdateMediaAction::run($media, [
        'name' => 'Homepage hero',
        'focal_point_x' => 32,
        'focal_point_y' => 68,
        'crop_presets' => ['thumbnail', 'hero', 'unknown'],
        'translations' => [
            [
                'language_id' => $language->getKey(),
                'title' => 'Homepage hero',
                'meta' => [
                    'alt' => 'Children running through a splash park',
                    'caption' => 'Summer campaign hero image',
                    'credit' => 'Capell Studio',
                    'decorative' => false,
                ],
            ],
        ],
    ]);

    $translation = Translation::query()
        ->where('language_id', $language->getKey())
        ->where('translatable_type', $updated->getMorphClass())
        ->where('translatable_id', $updated->getKey())
        ->firstOrFail();

    expect($updated->refresh())
        ->name->toBe('Homepage hero')
        ->and($updated->getFocalPoint())->toBe(['x' => 32, 'y' => 68])
        ->and($updated->getCropPresetNames())->toBe(['thumbnail', 'hero'])
        ->and($translation->title)->toBe('Homepage hero')
        ->and($translation->meta)->toMatchArray([
            'alt' => 'Children running through a splash park',
            'caption' => 'Summer campaign hero image',
            'credit' => 'Capell Studio',
        ])
        ->and($fileManipulator->calls)->toBe([
            [
                'names' => ['thumbnail', 'hero'],
                'responsive' => true,
            ],
        ]);
});
