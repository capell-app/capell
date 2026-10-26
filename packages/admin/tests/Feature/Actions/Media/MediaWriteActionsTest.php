<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Actions\Media\UpdateMediaAction;
use Capell\Admin\Actions\Media\UploadSiteMediaAction;
use Capell\Admin\Data\Media\MediaUploadAttemptData;
use Capell\Admin\Data\Media\MediaUploadFileData;
use Capell\Admin\Enums\MediaUploadStatus;
use Capell\Admin\Tests\Fixtures\SharedUploadPathGenerator;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\MediaLibrary\Support\FileRemover\FileBaseFileRemover;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

uses(CreatesAdminUser::class);

uses()->group('media');

beforeEach(function (): void {
    // Exercise durable commits rather than the harness rollback transaction.
    DB::select('select 1');
    DB::commit();
    Storage::fake('local');
    Storage::fake('public');
    config()->set('capell.media.model', Media::class);
    config()->set('media-library.media_model', Media::class);
});

it('creates ordered external video media for a site', function (): void {
    test()->actingAsAdmin();
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
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();

    Storage::disk('local')->put('media-uploads/hero-a.jpg', UploadedFile::fake()->image('hero-a.jpg')->getContent());
    Storage::disk('local')->put('media-uploads/hero-b.jpg', UploadedFile::fake()->image('hero-b.jpg')->getContent());

    $batch = UploadSiteMediaAction::run($site, [
        ['media-uploads/hero-a.jpg'],
        'media-uploads/hero-a.jpg',
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

    expect($batch->successfulCount())->toBe(2)
        ->and($batch->hasRetryableFiles())->toBeFalse()
        ->and($uploads)->toHaveCount(2)
        ->and($uploads->pluck('name')->all())->toBe(['hero-a', 'hero-b']);
});

it('reserves the same initial submission and recovers committed identities when source deletion fails', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $path = 'media-uploads/replayed.jpg';
    $local = Storage::disk('local');
    $bytes = UploadedFile::fake()->image('replayed.jpg')->getContent();
    $local->put($path, $bytes);
    $failingLocal = Mockery::mock($local);
    $failingLocal->shouldReceive('delete')->with($path)->andReturnFalse();
    Storage::set('local', $failingLocal);
    $uploader = UploadSiteMediaAction::make();

    // Both initial submissions are prepared before either starts storage I/O.
    $first = $uploader->prepare($site, [$path]);
    $second = $uploader->prepare($site, [[$path], $path]);
    expect($second->id)->toBe($first->id);

    $completed = $uploader->retry($first->id);
    $media = Media::query()->sole();
    $replayed = $uploader->prepare($site, [$path]);
    $result = $uploader->retry($second->id);

    expect($replayed->id)->toBe($first->id)
        ->and($replayed->files[0]->mediaId)->toBe($media->getKey())
        ->and($result->files[0]->mediaId)->toBe($media->getKey())
        ->and($completed->successfulCount())->toBe(1)
        ->and($completed->hasWarnings())->toBeTrue()
        ->and($completed->files[0]->reason)->toBe('capell-admin::media.upload_source_cleanup_failed')
        ->and(Media::query()->count())->toBe(1)
        ->and($local->get($path))->toBe($bytes)
        ->and(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toBe($bytes);
});

it('retains reconciliation destinations when compensation fails and cleans them before retrying', function (bool $forgetCheckpoint): void {
    test()->actingAsAdmin();
    Exceptions::fake();
    $site = Site::factory()->createOne();
    $path = 'media-uploads/reconcile.jpg';
    $bytes = UploadedFile::fake()->image('reconcile.jpg')->getContent();
    Storage::disk('local')->put($path, $bytes);
    config()->set('filesystems.disks.conversions', ['driver' => 'local']);
    Storage::fake('conversions');
    $public = Storage::disk('public');
    $existingPublicFiles = $public->allFiles();
    $conversions = Storage::disk('conversions');
    $failingPublic = Mockery::mock($public);
    $failingPublic->shouldReceive('delete')->andReturnFalse();
    Storage::set('public', $failingPublic);
    $conversionState = new class
    {
        public bool $fail = true;

        public int $attempts = 0;
    };
    $manipulator = Mockery::mock(FileManipulator::class)->makePartial();
    $manipulator->shouldReceive('createDerivedFiles')->andReturnUsing(function (SpatieMedia $media) use ($conversionState): void {
        $conversionState->attempts++;
        if ($conversionState->fail) {
            $paths = PathGeneratorFactory::create($media);
            Storage::disk($media->conversions_disk)->put($paths->getPathForConversions($media) . 'partial.jpg', 'partial conversion');
            Storage::disk($media->conversions_disk)->put($paths->getPathForResponsiveImages($media) . 'partial.jpg', 'partial responsive image');

            throw new RuntimeException('Conversion failed.');
        }
    });
    app()->instance(FileManipulator::class, $manipulator);
    config()->set('media-library.conversions_disk_name', 'conversions');

    $uploader = UploadSiteMediaAction::make();
    $failed = $uploader->handle($site, [$path]);
    expect($failed->files[0]->status)->toBe(MediaUploadStatus::ReconciliationRequired)
        ->and($failed->files[0]->reason)->toBe('capell-admin::media.upload_reconciliation_required')
        ->and($failed->files[0]->attempt)->not->toBeNull()
        ->and(expectPresent($uploader->outcome($failed->id))->files[0]->attempt?->toArray())->toEqual($failed->files[0]->attempt?->toArray())
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->get($path))->toBe($bytes)
        ->and(array_diff($public->allFiles(), $existingPublicFiles))->toHaveCount(1)
        ->and($conversions->allFiles())->toHaveCount(2);

    // No new asset may be created while compensation is still blocked.
    $stillBlocked = $uploader->retry($failed->id);
    expect($stillBlocked->files[0]->status)->toBe(MediaUploadStatus::ReconciliationRequired)
        ->and($conversionState->attempts)->toBe(1)
        ->and(Media::query()->count())->toBe(0);

    if ($forgetCheckpoint) {
        Cache::forget('capell:media-upload:' . $failed->id);
        $uploader = UploadSiteMediaAction::make();
        $restored = $uploader->prepare($site, [$path]);
        expect($restored->id)->toBe($failed->id)
            ->and($restored->files[0]->attempt?->toArray())->toEqual($failed->files[0]->attempt?->toArray());
        $blockedAfterCacheLoss = $uploader->retry($failed->id);
        expect($blockedAfterCacheLoss->files[0]->status)->toBe(MediaUploadStatus::ReconciliationRequired)
            ->and($conversionState->attempts)->toBe(1);
        Cache::forget('capell:media-upload:' . $failed->id);
    }

    Storage::set('public', $public);
    $conversionState->fail = false;
    $recovered = $uploader->retry($failed->id);
    $media = Media::query()->sole();
    expect($recovered->successfulCount())->toBe(1)
        ->and($recovered->files[0]->attempt)->toBeNull()
        ->and($conversionState->attempts)->toBe(2)
        ->and(array_values(array_diff($public->allFiles(), $existingPublicFiles)))->toBe([$media->getPathRelativeToRoot()])
        ->and($conversions->allFiles())->toBe([])
        ->and($public->get($media->getPathRelativeToRoot()))->toBe($bytes)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();

    Cache::forget('capell:media-upload:' . $failed->id);
    $replayed = UploadSiteMediaAction::make()->retry($failed->id);
    expect($replayed->files[0]->attempt)->toBeNull()
        ->and($replayed->files[0]->mediaId)->toBe($media->getKey())
        ->and($conversionState->attempts)->toBe(2)
        ->and(Media::query()->count())->toBe(1);
})->with([false, true]);

it('rejects an empty normalised submission before creating a batch', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();

    expect(fn () => UploadSiteMediaAction::make()->prepare($site, [[], '', null]))
        ->toThrow(ValidationException::class, __('capell-admin::media.upload_files_required'));
    expect(Media::query()->count())->toBe(0);
});

it('uses original upload names without changing stored filenames', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $path = 'media-uploads/random-storage-name.png';
    Storage::disk('local')->put($path, UploadedFile::fake()->image('source.png')->getContent());

    UploadSiteMediaAction::run($site, [$path], [$path => 'Campaign hero.png']);

    $media = Media::query()->sole();
    expect($media->name)->toBe('Campaign hero')
        ->and($media->file_name)->toBe('random-storage-name.png');
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
        #[Override]
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
        ->and($updated->getFocalPoint())->toEqual(['x' => 32, 'y' => 68])
        ->and($updated->getCropPresetNames())->toEqualCanonicalizing(['thumbnail', 'hero'])
        ->and($translation->title)->toBe('Homepage hero')
        ->and($translation->meta)->toMatchArray([
            'alt' => 'Children running through a splash park',
            'caption' => 'Summer campaign hero image',
            'credit' => 'Capell Studio',
        ])
        ->and($fileManipulator->calls)->toHaveCount(1)
        ->and($fileManipulator->calls[0]['names'])->toEqualCanonicalizing(['thumbnail', 'hero'])
        ->and($fileManipulator->calls[0]['responsive'])->toBeTrue();
});

it('rejects ambient transactions before reserving or consuming upload sources', function (bool $retry): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $path = 'media-uploads/outer-rollback.txt';
    Storage::disk('local')->put($path, 'source bytes');
    $uploader = UploadSiteMediaAction::make();
    $batch = $retry ? $uploader->prepare($site, [$path]) : null;
    $journals = Storage::disk('local')->allFiles('capell/media-upload-journal');

    DB::beginTransaction();
    try {
        expect(fn () => $retry ? $uploader->retry(expectPresent($batch)->id) : $uploader->handle($site, [$path]))
            ->toThrow(RuntimeException::class, 'Media uploads require an outermost commit.');
    } finally {
        DB::rollBack();
    }

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->get($path))->toBe('source bytes')
        ->and(Storage::disk('local')->allFiles('capell/media-upload-journal'))->toBe($journals);
    $recovered = $batch === null ? $uploader->handle($site, [$path]) : $uploader->retry($batch->id);
    expect($recovered->successfulCount())->toBe(1)
        ->and(Media::query()->count())->toBe(1);
})->with([false, true]);

it('refuses compensation in shared directories containing another committed upload', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    // A captured its empty destination snapshot before B committed here.
    $attempt = new MediaUploadAttemptData(999999, [
        ['disk' => 'public', 'directory' => 'shared', 'existingFiles' => []],
    ]);
    config()->set('media-library.path_generator', SharedUploadPathGenerator::class);
    config()->set('media-library.file_remover_class', FileBaseFileRemover::class);

    $other = $site->addMedia(UploadedFile::fake()->createWithContent('other.txt', 'committed B'))->toMediaCollection('uploads');
    $path = $other->getPathRelativeToRoot();
    $clean = new ReflectionMethod(UploadSiteMediaAction::class, 'cleanAttempt');

    expect(fn (): mixed => $clean->invoke(UploadSiteMediaAction::make(), $attempt))
        ->toThrow(RuntimeException::class, 'The upload destination is not exclusive to this media.');
    expect($other->fresh())->not->toBeNull()
        ->and(Storage::disk('public')->get($path))->toBe('committed B');
});

it('rejects shared destination layouts before writing or converting an upload', function (): void {
    test()->actingAsAdmin();
    Exceptions::fake();
    $site = Site::factory()->createOne();
    config()->set('media-library.path_generator', SharedUploadPathGenerator::class);
    config()->set('media-library.file_remover_class', FileBaseFileRemover::class);

    $other = $site->addMedia(UploadedFile::fake()->createWithContent('other.txt', 'committed B'))->toMediaCollection('uploads');
    $before = Storage::disk('public')->allFiles();
    $manipulator = Mockery::mock(FileManipulator::class)->makePartial();
    $manipulator->shouldNotReceive('createDerivedFiles');

    app()->instance(FileManipulator::class, $manipulator);
    Storage::disk('local')->put('media-uploads/attempt-a.txt', 'attempt A');

    $batch = UploadSiteMediaAction::run($site, ['media-uploads/attempt-a.txt']);

    expect($batch->files[0]->status)->toBe(MediaUploadStatus::Failed)
        ->and(Storage::disk('local')->get('media-uploads/attempt-a.txt'))->toBe('attempt A')
        ->and(Storage::disk('public')->allFiles())->toBe($before)
        ->and(Storage::disk('public')->get($other->getPathRelativeToRoot()))->toBe('committed B')
        ->and(Media::query()->count())->toBe(1);
});

it('retains an unproven shared destination containing the failed media identity', function (): void {
    test()->actingAsAdmin();
    Exceptions::fake();
    $site = Site::factory()->createOne();
    $uploader = UploadSiteMediaAction::make();
    Storage::disk('local')->put('media-uploads/retained-a.txt', 'attempt A');
    $batch = $uploader->prepare($site, ['media-uploads/retained-a.txt']);
    $attempt = new MediaUploadAttemptData(1, [
        ['disk' => 'public', 'directory' => 'shared-1', 'existingFiles' => []],
    ]);
    $other = Media::query()->create([
        'id' => 2, 'model_type' => $site->getMorphClass(), 'model_id' => $site->getKey(),
        'collection_name' => 'uploads', 'name' => 'committed B', 'file_name' => 'committed-b.txt',
        'disk' => 'public', 'conversions_disk' => 'public', 'size' => 11,
        'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
    ]);
    Storage::disk('public')->put('shared-1/committed-b.txt', 'committed B');
    $retained = $batch->withFile(0, new MediaUploadFileData(
        $batch->files[0]->path,
        $batch->files[0]->name,
        MediaUploadStatus::ReconciliationRequired,
        'capell-admin::media.upload_reconciliation_required',
        attempt: $attempt,
    ));
    $remember = new ReflectionMethod(UploadSiteMediaAction::class, 'remember');
    $remember->invoke($uploader, $retained);
    Cache::forget('capell:media-upload:' . $batch->id);

    $clean = new ReflectionMethod(UploadSiteMediaAction::class, 'cleanAttempt');
    expect(fn (): mixed => $clean->invoke($uploader, $attempt))
        ->toThrow(RuntimeException::class, 'The upload destination is not exclusive to this media.');
    $retried = $uploader->retry($batch->id);
    expect($retried->files[0]->status)->toBe(MediaUploadStatus::ReconciliationRequired)
        ->and($retried->files[0]->attempt?->toArray())->toEqual($attempt->toArray())
        ->and(Media::query()->count())->toBe(1)
        ->and($other->fresh())->not->toBeNull()
        ->and(Storage::disk('public')->get('shared-1/committed-b.txt'))->toBe('committed B')
        ->and(Storage::disk('local')->get('media-uploads/retained-a.txt'))->toBe('attempt A');
});

it('restores scalar journal statuses and attempt provenance without reading a legacy object cache', function (MediaUploadStatus $status): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $uploader = UploadSiteMediaAction::make();
    $batch = $uploader->prepare($site, ['media-uploads/checkpoint.txt']);
    $journal = 'capell/media-upload-journal/' . hash('sha256', $batch->id) . '.json';
    $payload = json_decode(expectPresent(Storage::disk('local')->get($journal)), true, 64, JSON_THROW_ON_ERROR);
    $payload['files'][0]['status'] = $status->value;
    $payload['files'][0]['attempt'] = [
        'mediaId' => 123,
        'destinations' => [['disk' => 'public', 'directory' => '123', 'existingFiles' => []]],
        'pathGenerator' => DefaultPathGenerator::class,
    ];
    Storage::disk('local')->put($journal, json_encode($payload, JSON_THROW_ON_ERROR));

    // Old cached DTOs can contain the former enum class name. The durable
    // scalar journal must be read before any cache deserialisation is attempted.
    Cache::shouldReceive('get')->never();
    $recovered = $uploader->outcome($batch->id);

    expect($recovered)->not->toBeNull()
        ->and($recovered?->files[0]->status)->toBe($status)
        ->and($recovered?->files[0]->attempt?->toArray())->toBe($payload['files'][0]['attempt'])
        ->and($recovered?->toArray())->toBe($payload);
})->with(MediaUploadStatus::cases());

it('refuses compensation when a captured media owner is outside the actors grants', function (bool $trashed): void {
    test()->actingAsAdmin();
    $assignedSite = Site::factory()->createOne();
    $foreignSite = Site::factory()->createOne();
    $media = $foreignSite->addMedia(UploadedFile::fake()->createWithContent('owned.txt', 'committed bytes'))->toMediaCollection('uploads');
    if ($trashed) {
        $media->delete();
    }

    $path = $media->getPathRelativeToRoot();
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([(int) $assignedSite->getKey()]);

    test()->actingAs($actor);
    expect(SiteAccess::current()->query(Media::class)->withTrashed()->whereKey($media->getKey())->exists())->toBeFalse();

    $attempt = new MediaUploadAttemptData((int) $media->getKey(), [
        ['disk' => 'public', 'directory' => (string) $media->getKey(), 'existingFiles' => []],
    ], DefaultPathGenerator::class);
    $clean = new ReflectionMethod(UploadSiteMediaAction::class, 'cleanAttempt');

    expect(fn (): mixed => $clean->invoke(UploadSiteMediaAction::make(), $attempt))
        ->toThrow(RuntimeException::class, 'The upload destination still has a media owner.');
    expect(Media::withTrashed()->whereKey($media->getKey())->exists())->toBeTrue()
        ->and(Storage::disk('public')->get($path))->toBe('committed bytes');
})->with([false, true]);
