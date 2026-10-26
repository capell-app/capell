<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\BuildMediaUsageItemsAction;
use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Actions\Media\UploadSiteMediaAction;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\MediaUploadStatus;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Admin\Filament\Resources\Media\Tables\MediaTable;
use Capell\Admin\Tests\Fixtures\CreationDeniedMediaResource;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class)
    ->group('media');

beforeEach(function (): void {
    test()->actingAsAdmin();
    // Uploads must commit independently of the test harness rollback.
    DB::commit();

    Storage::fake('local');
    Storage::fake('public');
    config()->set('capell.media.model', CapellMedia::class);
    config()->set('media-library.media_model', CapellMedia::class);
});

it('lists each media owner type once using its canonical alias', function (): void {
    $component = Livewire::test(ListMedia::class)->assertSuccessful();
    $filter = $component->instance()->getTable()->getFilter('model_type');
    assert($filter instanceof SelectFilter);

    expect($filter->getOptions())
        ->toHaveKey('page', 'Page')
        ->not->toHaveKey(Page::class);
});

it('reports a rejected replacement without a success notification or broken media reference', function (): void {
    $original = Page::factory()->createOne()->addMedia(UploadedFile::fake()->image('original.png'))->toMediaCollection('images');
    $url = $original->getUrl();
    $replacement = UploadedFile::fake()->createWithContent('replacement.txt', 'different media type');

    Livewire::test(ListMedia::class)
        ->callTableAction('replace-file', $original, data: ['replacement' => [$replacement]])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('capell-admin::media.replace_file_failed'))
        ->assertNotNotified(__('capell-admin::media.replace_file_success'));

    expect(CapellMedia::query()->whereKey($original->getKey())->firstOrFail()->getUrl())->toBe($url)
        ->and(Storage::disk('public')->exists($original->getPathRelativeToRoot()))->toBeTrue();
});

it('reports committed replacement cleanup as a warning without inviting a failed replacement retry', function (): void {
    $original = Page::factory()->createOne()->addMedia(UploadedFile::fake()->image('original.png'))->toMediaCollection('images');
    $replacement = UploadedFile::fake()->image('replacement.png', 48, 48);
    $bytes = file_get_contents($replacement->getPathname());
    throw_unless(is_string($bytes), RuntimeException::class, 'Replacement fixture is missing.');
    $public = Storage::disk('public');
    $failingPublic = Mockery::mock($public);
    $failingPublic->shouldReceive('deleteDirectory')->andReturnFalse();
    Storage::set('public', $failingPublic);
    File::partialMock()->shouldReceive('deleteDirectory')
        ->withArgs(fn (string $path): bool => str_contains($path, '/capell-media-replacement/'))
        ->andReturnFalse();
    Exceptions::fake();
    $before = glob(storage_path('app/private/capell-media-replacement/*')) ?: [];

    try {
        Livewire::test(ListMedia::class)
            ->callTableAction('replace-file', $original, data: ['replacement' => [$replacement]])
            ->assertHasNoTableActionErrors()
            ->assertNotified(Notification::make()
                ->title(__('capell-admin::media.replace_file_success'))
                ->body(__('capell-admin::media.replace_file_cleanup_warning'))
                ->warning())
            ->assertNotNotified(__('capell-admin::media.replace_file_failed'));

        expect($public->get($original->getPathRelativeToRoot()))->toBe($bytes)
            ->and(CapellMedia::query()->count())->toBe(1);
    } finally {
        foreach (array_diff(glob(storage_path('app/private/capell-media-replacement/*')) ?: [], $before) as $directory) {
            new Filesystem()->deleteDirectory($directory);
        }
    }
});

it('lists media with owner labels filters and owner edit actions', function (): void {
    Route::get('/admin/pages/{record}/edit', fn (): string => '')
        ->name('filament.admin.resources.pages.edit');

    $owner = Page::factory()->createOne(['name' => 'Media owner page']);
    $otherOwner = Page::factory()->createOne(['name' => 'Document owner page']);

    $image = $owner
        ->addMedia(UploadedFile::fake()->image('hero.jpg', 600, 400))
        ->usingName('Hero image')
        ->toMediaCollection('images');

    $document = $otherOwner
        ->addMedia(UploadedFile::fake()->create('terms.pdf', 20, 'application/pdf'))
        ->usingName('Terms PDF')
        ->toMediaCollection('documents');
    $document->forceFill(['mime_type' => 'application/pdf'])->save();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->toggleAllTableColumns()
        ->assertCanSeeTableRecords([$image, $document])
        ->assertSee(__('capell-admin::media.mime_groups.image'))
        ->assertSee(__('capell-admin::media.mime_groups.pdf'))
        ->assertSee('hero.jpg')
        ->assertSee('terms.pdf')
        ->assertSee('Media owner page')
        ->assertTableActionVisible('open-owner', $image);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('collection_name', 'images')
        ->assertCanSeeTableRecords([$image])
        ->assertCanNotSeeTableRecords([$document]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('mime_group', 'application/pdf')
        ->assertCanSeeTableRecords([$document])
        ->assertCanNotSeeTableRecords([$image]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('mime_group', 'image')
        ->assertCanSeeTableRecords([$image])
        ->assertCanNotSeeTableRecords([$document]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('model_type', $image->model_type)
        ->assertCanSeeTableRecords([$image, $document]);
});

it('hides owner edit actions when the actor cannot edit the owner record', function (): void {
    Route::get('/admin/pages/{record}/edit', fn (): string => '')
        ->name('filament.admin.resources.pages.edit');

    $owner = Page::factory()->createOne(['name' => 'Media owner page']);
    $image = $owner
        ->addMedia(UploadedFile::fake()->image('hero.jpg', 600, 400))
        ->usingName('Hero image')
        ->toMediaCollection('images');

    expect(MediaTable::getOwnerUrl($image))->not->toBeNull();

    test()->actingAsUser();

    $image->refresh();

    expect(MediaTable::getOwnerUrl($image))->toBeNull();
});

it('hides owner actions when the related model cannot be resolved to an admin resource', function (): void {
    $orphan = CapellMedia::query()->create([
        'model_type' => Page::class,
        'model_id' => 123,
        'uuid' => (string) Str::uuid(),
        'collection_name' => 'images',
        'name' => 'Orphan',
        'file_name' => 'orphan.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 100,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ]);

    expect(MediaTable::getOwnerUrl($orphan))->toBeNull();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$orphan])
        ->assertTableActionHidden('open-owner', $orphan);
});

it('edits modal-only media owners from the grouped owner actions', function (): void {
    $theme = Theme::factory()->createOne(['name' => 'Media owner theme']);
    $media = $theme
        ->addMedia(UploadedFile::fake()->image('theme.jpg', 600, 400))
        ->toMediaCollection('images');

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$media])
        ->assertTableActionVisible('edit-owner-theme', $media)
        ->mountTableAction('edit-owner-theme', $media)
        ->assertMountedActionModalSee('Media owner theme');
});

it('creates YouTube video media from the list page action', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->callAction('add-youtube-video', [
            'name' => 'Product tour',
            'youtube_url' => 'https://youtu.be/FgalLC99jzY',
            'site_id' => $site->getKey(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $media = CapellMedia::query()
        ->where('model_type', $site->getMorphClass())
        ->where('model_id', $site->getKey())
        ->where('collection_name', MediaCollectionEnum::Video->value)
        ->firstOrFail();

    expect($media->name)->toBe('Product tour')
        ->and($media->externalVideo()?->provider)->toBe('youtube')
        ->and($media->externalVideo()?->videoId)->toBe('FgalLC99jzY');
});

it('uses the external video thumbnail in the media table instead of a storage URL', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);
    $video = expectPresent(YouTubeVideoUrl::parse('https://youtu.be/FgalLC99jzY'));

    $media = CreateExternalVideoMediaAction::run($site, 'Product tour', $video);

    expect($media->original_url)->toBe($video->thumbnailUrl)
        ->and($media->original_url)->not->toContain('/storage/')
        ->and($media->original_url)->not->toEndWith('.youtube');

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertSee($video->thumbnailUrl)
        ->assertDontSee('/storage/' . $media->getKey() . '/' . $media->file_name);
});

it('does not render a synthetic storage preview for a local external video', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);
    $video = new ExternalVideoData(
        provider: 'local',
        videoId: 'capell-launch-film',
        url: '/_capell/marketing/page-videos/capell-launch-film/capell-launch-film.mp4',
        embedUrl: '/_capell/marketing/page-videos/capell-launch-film/capell-launch-film.mp4',
        thumbnailUrl: '/_capell/marketing/page-videos/capell-launch-film/capell-launch-film-poster.jpg',
    );

    $media = CreateExternalVideoMediaAction::run($site, 'Capell launch film', $video);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$media])
        ->assertDontSee('/storage/' . $media->getKey() . '/' . $media->file_name)
        ->assertDontSee($video->thumbnailUrl);
});

it('finds renamed media by its editor name and storage filename', function (): void {
    $site = Site::factory()->createOne();
    $media = $site->addMedia(UploadedFile::fake()->image('random-storage.png'))
        ->usingName('Campaign hero')
        ->toMediaCollection('uploads');

    Livewire::test(ListMedia::class)
        ->assertTableColumnStateSet('name', 'Campaign hero', $media)
        ->searchTable('Campaign hero')
        ->assertCanSeeTableRecords([$media])
        ->searchTable('random-storage.png')
        ->assertCanSeeTableRecords([$media]);
});

it('bulk uploads files to a site uploads collection', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->callAction('upload-files', [
            'site_id' => $site->getKey(),
            'files' => [
                UploadedFile::fake()->image('hero-a.jpg'),
                UploadedFile::fake()->image('hero-b.jpg'),
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $uploads = CapellMedia::query()
        ->where('model_type', $site->getMorphClass())
        ->where('model_id', $site->getKey())
        ->where('collection_name', 'uploads')
        ->orderBy('name')
        ->get();

    expect($uploads)->toHaveCount(2)
        ->and($uploads->pluck('name')->all())->toBe(['hero-a', 'hero-b']);
});

it('passes original upload names from the upload form to media creation', function (): void {
    $site = Site::factory()->createOne();
    Livewire::test(ListMedia::class)
        ->callAction('upload-files', [
            'site_id' => $site->getKey(),
            'files' => [UploadedFile::fake()->image('Campaign hero.png')],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $media = CapellMedia::query()->sole();
    expect($media->name)->toBe('Campaign hero')
        ->and($media->file_name)->not->toBe('Campaign hero.png');
});

it('reuses a reservation when two copies of one snapshot dehydrate before either creates media', function (): void {
    $site = Site::factory()->createOne();
    $existingPublicFiles = Storage::disk('public')->allFiles();
    $local = Storage::disk('local');
    $preservedTemporaryFiles = Mockery::mock($local);
    $storageState = new class
    {
        public ?ListMedia $page = null;
    };
    $preservedTemporaryFiles->shouldReceive('put')->andReturnUsing(function (...$arguments) use ($local, $storageState): bool {
        if (str_contains($arguments[0], '.part.')) {
            $page = expectPresent($storageState->page);
            $id = expectPresent($page->uploadBatchId);
            expect($id)->toBeString();
            $reserved = expectPresent(UploadSiteMediaAction::make()->outcome($id));
            expect($reserved->hasRetryableFiles())->toBeTrue();
        }

        return $local->put(...$arguments) !== false;
    });
    // Hold temporary deletion until both form storage callbacks have finished,
    // reproducing the window where two requests can copy the same upload.
    $preservedTemporaryFiles->shouldReceive('delete')
        ->andReturnUsing(fn (string|array $path): bool => is_string($path) && str_contains($path, 'livewire-tmp/') ? true : $local->delete($path));
    Storage::set('local', $preservedTemporaryFiles);
    config()->set('livewire.temporary_file_upload.disk', 'local');

    $first = Livewire::test(ListMedia::class)->mountAction('upload-files')->fillForm([
        'site_id' => $site->getKey(),
        'files' => [UploadedFile::fake()->image('Snapshot hero.png')],
    ]);
    $second = clone $first;
    $second->call('$refresh');

    // Drive Filament's real validation/dehydration boundary for both copies,
    // then release their action callbacks. No pre-stored paths are supplied.
    /** @var list<array{ListMedia, Action, array<string, mixed>}> $submissions */
    $submissions = [];
    foreach ([$first, $second] as $request) {
        $page = $request->instance();
        assert($page instanceof ListMedia);
        $storageState->page = $page;
        $action = $page->getMountedAction();
        assert($action instanceof Action);
        $action->callBeforeFormValidated();
        $schemaName = $page->getMountedActionSchemaName();
        assert($schemaName !== null);
        $schema = $page->getSchema($schemaName);
        assert($schema instanceof Schema);
        $data = $schema->getState();
        $action->data($data);
        $submissions[] = [$page, $action, $data];
        // Neither the reserved path mapping nor the later attempt may depend
        // on this expendable cache value surviving the second request.
        foreach (Storage::disk('local')->files('capell/media-upload-journal') as $journal) {
            $checkpoint = json_decode(expectPresent($local->get($journal)), true, 64, JSON_THROW_ON_ERROR);
            Cache::forget('capell:media-upload:' . $checkpoint['id']);
        }
    }

    expect($submissions[1][2]['files'])->toBe($submissions[0][2]['files'])
        ->and($local->allFiles('media-uploads'))->toHaveCount(1)
        ->and(CapellMedia::query()->count())->toBe(0);
    $path = $submissions[0][2]['files'][0];
    $bytes = $local->get($path);
    foreach ($submissions as [, $action]) {
        $action->call();
    }

    $media = CapellMedia::query()->sole();
    $expectedFiles = [$media->getPathRelativeToRoot(), ...$media->getGeneratedConversions()
        ->filter()->keys()->map(fn (string $conversion): string => $media->getPathRelativeToRoot($conversion))->all()];
    expect($submissions[1][0]->uploadBatchId)->toBe($submissions[0][0]->uploadBatchId)
        ->and($media->getCustomProperty('upload_batch'))->toBe($submissions[0][0]->uploadBatchId)
        ->and($media->name)->toBe('Snapshot hero')
        ->and(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toBe($bytes)
        ->and(array_values(array_diff(Storage::disk('public')->allFiles(), $existingPublicFiles)))->toEqualCanonicalizing($expectedFiles)
        ->and($local->allFiles('media-uploads'))->toBe([]);
});

it('rejects a batch addressed to a site outside the actors current access', function (): void {
    $assignedSite = Site::factory()->createOne();
    $foreignSite = Site::factory()->createOne();
    Permission::findOrCreate('ViewAny:Media');
    Permission::findOrCreate('Create:Media');
    $actor = test()->createUserWithPermission(['ViewAny:Media', 'Create:Media']);
    $actor->assignedSiteIds = collect([$assignedSite->getKey()]);

    test()->actingAs($actor);

    Livewire::test(ListMedia::class)
        ->callAction('upload-files', [
            'site_id' => $foreignSite->getKey(),
            'files' => [UploadedFile::fake()->image('foreign.jpg')],
        ])
        ->assertHasActionErrors(['site_id']);

    expect(CapellMedia::query()->count())->toBe(0);
});

it('rejects an upload storage callback that returns no paths without notifying success', function (): void {
    $site = Site::factory()->createOne();

    $uploader = Mockery::mock(UploadSiteMediaAction::make());
    $uploader->shouldReceive('storeTemporaryUpload')->andReturnNull();
    app()->instance(UploadSiteMediaAction::class, $uploader);
    Livewire::test(ListMedia::class)
        ->callAction('upload-files', [
            'site_id' => $site->getKey(),
            'files' => [UploadedFile::fake()->image('lost.jpg')],
        ])
        ->assertHasActionErrors(['files'])
        ->assertSet('uploadBatchId', fn (mixed $id): bool => is_string($id) && expectPresent(UploadSiteMediaAction::make()->outcome($id))->hasRetryableFiles())
        ->assertNotNotified();

    expect(CapellMedia::query()->count())->toBe(0);
});

describe('recoverable upload batches', function (): void {
    beforeEach(function (): void {
        $this->uploadAttempts = [];
        $this->failNextUpload = true;
        $manipulator = Mockery::mock(FileManipulator::class)->makePartial();
        $manipulator->shouldReceive('createDerivedFiles')->andReturnUsing(function (SpatieMedia $media): void {
            $this->uploadAttempts[] = $media->name;
            if ($media->name === 'hero-b' && $this->failNextUpload) {
                $this->failNextUpload = false;
                $paths = PathGeneratorFactory::create($media);
                Storage::disk($media->conversions_disk)->put($paths->getPathForConversions($media) . 'partial.jpg', 'partial conversion');
                Storage::disk($media->conversions_disk)->put($paths->getPathForResponsiveImages($media) . 'partial.jpg', 'partial responsive image');

                throw new RuntimeException('Storage failed at /private/secret-path with secret-token');
            }
        });
        app()->instance(FileManipulator::class, $manipulator);
        Exceptions::fake();
        $this->uploadSite = Site::factory()->createOne();
        $this->existingPublicFiles = Storage::disk('public')->allFiles();
        $this->uploadPage = Livewire::test(ListMedia::class)
            ->callAction('upload-files', [
                'site_id' => $this->uploadSite->getKey(),
                'files' => [
                    UploadedFile::fake()->image('hero-a.jpg'),
                    UploadedFile::fake()->image('hero-b.jpg'),
                    UploadedFile::fake()->image('hero-c.jpg'),
                ],
            ])
            ->assertHasNoActionErrors();
        $this->uploadBatchId = $this->uploadPage->get('uploadBatchId');
    });

    it('retains and reports each committed failed and unattempted file without exposing diagnostics', function (): void {
        $batch = expectPresent(UploadSiteMediaAction::make()->outcome($this->uploadBatchId));
        expect($batch)->not->toBeNull();
        $saved = CapellMedia::query()->sole();

        expect($batch->successfulCount())->toBe(1)
            ->and(array_column($batch->files, 'status'))->toBe([
                MediaUploadStatus::Successful,
                MediaUploadStatus::Failed,
                MediaUploadStatus::Unresolved,
            ])
            ->and($batch->files[0]->mediaId)->toBe($saved->getKey())
            ->and($saved->name)->toBe('hero-a')
            ->and(Storage::disk('public')->exists($saved->getPathRelativeToRoot()))->toBeTrue()
            ->and(Storage::disk('local')->exists($batch->files[1]->path))->toBeTrue()
            ->and(Storage::disk('local')->exists($batch->files[2]->path))->toBeTrue()
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b']);

        $ownedFiles = CapellMedia::query()->get()->flatMap(function (CapellMedia $media): array {
            $paths = PathGeneratorFactory::create($media);

            return Storage::disk($media->disk)->allFiles($paths->getPath($media));
        })->unique()->all();
        expect(array_values(array_diff(Storage::disk('public')->allFiles(), $this->existingPublicFiles)))->toEqualCanonicalizing($ownedFiles);

        $body = implode(' ', [
            __('capell-admin::media.upload_file_outcome', ['name' => 'hero-a.jpg', 'reason' => __('capell-admin::media.upload_file_successful')]),
            __('capell-admin::media.upload_file_outcome', ['name' => 'hero-b.jpg', 'reason' => __('capell-admin::media.upload_file_failed')]),
            __('capell-admin::media.upload_file_outcome', ['name' => 'hero-c.jpg', 'reason' => __('capell-admin::media.upload_not_attempted')]),
        ]);
        $this->uploadPage
            ->assertCanSeeTableRecords([$saved])
            ->assertActionVisible('retry-upload-files')
            ->assertNotified(Notification::make()
                ->title(trans_choice('capell-admin::media.upload_files_success', 1, ['count' => 1]))
                ->body($body)
                ->warning()
                ->persistent());

        $notifications = json_encode(session('filament.notifications', []), JSON_THROW_ON_ERROR);
        expect($notifications)->not->toContain('secret-path', 'secret-token', 'RuntimeException');
    });

    it('retries only failed and unattempted files and retains the original successful media', function (): void {
        $first = CapellMedia::query()->sole();
        $firstBytes = Storage::disk('public')->get($first->getPathRelativeToRoot());

        $this->uploadPage
            ->callAction('retry-upload-files')
            ->assertHasNoActionErrors()
            ->assertNotified(trans_choice('capell-admin::media.upload_files_success', 3, ['count' => 3]))
            ->assertActionHidden('retry-upload-files');

        $batch = expectPresent(UploadSiteMediaAction::make()->outcome($this->uploadBatchId));
        expect($batch->successfulCount())->toBe(3)
            ->and($batch->hasRetryableFiles())->toBeFalse()
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b', 'hero-b', 'hero-c'])
            ->and(CapellMedia::query()->orderBy('name')->pluck('name')->all())->toBe(['hero-a', 'hero-b', 'hero-c'])
            ->and(CapellMedia::query()->whereKey($first->getKey())->exists())->toBeTrue()
            ->and(Storage::disk('public')->get($first->getPathRelativeToRoot()))->toBe($firstBytes);

        // A replay after completion must use the server checkpoint, even if
        // a client retained the old retry button.
        UploadSiteMediaAction::make()->retry($this->uploadBatchId);
        expect(CapellMedia::query()->count())->toBe(3)
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b', 'hero-b', 'hero-c']);
    });

    it('reconciles a committed file if its outcome checkpoint is stale instead of uploading it again', function (): void {
        $stale = expectPresent(UploadSiteMediaAction::make()->outcome($this->uploadBatchId));
        $this->uploadPage->callAction('retry-upload-files')->assertHasNoActionErrors();
        $ids = CapellMedia::query()->orderBy('id')->pluck('id')->all();
        Cache::put('capell:media-upload:' . $this->uploadBatchId, $stale, now()->addHour());
        Storage::disk('local')->put('capell/media-upload-journal/' . hash('sha256', $this->uploadBatchId) . '.json', $stale->toJson(JSON_THROW_ON_ERROR));

        $this->uploadPage->callAction('retry-upload-files')->assertHasNoActionErrors();

        expect(CapellMedia::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b', 'hero-b', 'hero-c'])
            ->and(expectPresent(UploadSiteMediaAction::make()->outcome($this->uploadBatchId))->successfulCount())->toBe(3);
    });

    it('does not recreate a completed file removed before a stale retry', function (): void {
        $stale = expectPresent(UploadSiteMediaAction::make()->outcome($this->uploadBatchId));
        $this->uploadPage->callAction('retry-upload-files')->assertHasNoActionErrors();
        $removed = CapellMedia::query()->where('name', 'hero-b')->sole();
        $removed->delete();
        Cache::put('capell:media-upload:' . $this->uploadBatchId, $stale, now()->addHour());
        Storage::disk('local')->put('capell/media-upload-journal/' . hash('sha256', $this->uploadBatchId) . '.json', $stale->toJson(JSON_THROW_ON_ERROR));

        $this->uploadPage->callAction('retry-upload-files')->assertHasNoActionErrors();

        expect(CapellMedia::withTrashed()->count())->toBe(3)
            ->and(CapellMedia::query()->count())->toBe(2)
            ->and($removed->refresh()->trashed())->toBeTrue()
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b', 'hero-b', 'hero-c']);
    });

    it('does not allow a different authorised actor to resume the original actors files', function (): void {
        test()->actingAsAdmin();
        expect(Gate::allows('create', CapellMedia::class))->toBeTrue()
            ->and(UploadSiteMediaAction::make()->outcome($this->uploadBatchId))->toBeNull();

        $this->uploadPage
            ->call('$refresh')
            ->assertActionHidden('retry-upload-files')
            ->call('mountAction', 'retry-upload-files')
            ->call('callMountedAction')
            ->assertActionNotMounted();

        expect(fn () => UploadSiteMediaAction::make()->retry($this->uploadBatchId))
            ->toThrow(HttpException::class);
        expect(CapellMedia::query()->count())->toBe(1)
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b']);
    });

    it('rechecks media creation permission after retry is mounted', function (): void {
        $this->uploadPage->mountAction('retry-upload-files');
        $actor = test()->authenticatedUser();
        Permission::findOrCreate('ViewAny:Media');
        $actor->givePermissionTo('ViewAny:Media');
        $actor->assignedSiteIds = collect([$this->uploadSite->getKey()]);
        $actor->syncRoles([]);

        expect(Gate::allows('create', CapellMedia::class))->toBeFalse();

        $this->uploadPage->callMountedAction();

        expect(fn () => UploadSiteMediaAction::make()->retry($this->uploadBatchId))
            ->toThrow(AuthorizationException::class);
        expect(CapellMedia::query()->count())->toBe(1)
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b']);
    });

    it('rechecks site access after retry is mounted', function (): void {
        $this->uploadPage->mountAction('retry-upload-files');
        $actor = test()->authenticatedUser();
        Permission::findOrCreate('ViewAny:Media');
        Permission::findOrCreate('Create:Media');
        $actor->givePermissionTo(['ViewAny:Media', 'Create:Media']);
        $actor->syncRoles([]);
        $actor->assignedSiteIds = collect();

        $this->uploadPage->callMountedAction();

        expect(fn () => UploadSiteMediaAction::make()->retry($this->uploadBatchId))
            ->toThrow(ModelNotFoundException::class);
        expect(CapellMedia::query()->count())->toBe(1)
            ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b']);
    });

    it('honours a resource creation denial introduced after retry is mounted', function (): void {
        $this->uploadPage->mountAction('retry-upload-files');
        CapellAdmin::getAdminSurfaceRegistry()->replace(AdminSurfaceContributionData::resource(CreationDeniedMediaResource::class, 'Media'));

        try {
            $this->uploadPage->callMountedAction();
            expect(CapellMedia::query()->count())->toBe(1)
                ->and($this->uploadAttempts)->toBe(['hero-a', 'hero-b']);
        } finally {
            CapellAdmin::getAdminSurfaceRegistry()->replace(AdminSurfaceContributionData::resource(MediaResource::class, 'Media'));
        }
    });
});

it('shows usage counts and can filter recently deleted media', function (): void {
    $owner = Page::factory()->createOne(['name' => 'Media owner page']);
    $image = CapellMedia::factory()
        ->model($owner)
        ->createOne(['name' => 'Hero image', 'file_name' => 'hero.jpg']);
    $deletedImage = CapellMedia::factory()
        ->model($owner)
        ->createOne(['name' => 'Deleted hero', 'file_name' => 'deleted-hero.jpg']);

    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $image->getMorphClass(),
        'asset_id' => $image->getKey(),
        'order' => 1,
    ]);

    $deletedImage->delete();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$image])
        ->assertCanNotSeeTableRecords([$deletedImage])
        ->assertTableColumnExists('usage_count')
        ->assertTableColumnStateSet('usage_count', 1, $image);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$deletedImage])
        ->assertCanNotSeeTableRecords([$image]);
});

it('projects tracked usage counts without per-row attachment queries', function (): void {
    $owner = Page::factory()->createOne();
    $media = CapellMedia::factory()
        ->count(6)
        ->model($owner)
        ->create();

    $media->each(function (CapellMedia $item, int $index) use ($owner): void {
        AssetAttachment::query()->create([
            'related_type' => $owner->getMorphClass(),
            'related_id' => $owner->getKey(),
            'asset_type' => $item->getMorphClass(),
            'asset_id' => $item->getKey(),
            'order' => $index + 1,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($media)
        ->assertTableColumnStateSet('usage_count', 1, $media->first());

    $perRowAttachmentCounts = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(static fn (string $query): bool => str_starts_with(strtolower($query), 'select count(*)')
            && str_contains($query, 'asset_attachments'));

    expect($perRowAttachmentCounts)->toBeEmpty();
});

it('filters media by tracked attachment use and links positive usage counts', function (): void {
    $owner = Page::factory()->createOne();

    Permission::findOrCreate('Update:Media');
    test()->authenticatedUser()->givePermissionTo('Update:Media');

    $usedMedia = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'Used image',
        'file_name' => 'used.jpg',
    ]);
    $unusedMedia = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'No tracked uses image',
        'file_name' => 'no-tracked-uses.jpg',
    ]);

    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $usedMedia->getMorphClass(),
        'asset_id' => $usedMedia->getKey(),
        'order' => 1,
    ]);

    $livewire = Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertTableFilterExists('tracked_use')
        ->filterTable('tracked_use', 'unused')
        ->assertCanSeeTableRecords([$unusedMedia])
        ->assertCanNotSeeTableRecords([$usedMedia])
        ->assertSee(__('capell-admin::table.no_tracked_uses'));

    $usedLivewire = Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('tracked_use', 'used')
        ->assertCanSeeTableRecords([$usedMedia])
        ->assertCanNotSeeTableRecords([$unusedMedia]);

    $usageColumn = $livewire->instance()->getTable()->getColumn('usage_count');

    expect($usageColumn)->not->toBeNull();

    $usedMedia = $usedLivewire->instance()->getTableRecords()->firstWhere('id', $usedMedia->getKey());

    expect($usedMedia)->toBeInstanceOf(CapellMedia::class);

    $usageColumn->record($usedMedia);

    expect($usageColumn->getUrl($usageColumn->getState()))
        ->toBe(MediaResource::getUrl('edit', ['record' => $usedMedia]));

    $unusedMedia = $livewire->instance()->getTableRecords()->firstWhere('id', $unusedMedia->getKey());

    expect($unusedMedia)->toBeInstanceOf(CapellMedia::class);

    $usageColumn->record($unusedMedia);

    expect($usageColumn->getUrl($usageColumn->getState()))
        ->toBeNull()
        ->and($usageColumn->getTooltip($usageColumn->getState()))
        ->toBe(__('capell-admin::table.asset_usage_no_tracked_uses_tooltip'))
        ->toBe('No tracked uses');
});

it('unifies media health state filters and guarded bulk actions in the media workbench', function (): void {
    $owner = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $missingAlt = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'Needs alt text',
    ]);
    $healthy = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'Healthy image',
    ]);

    Translation::query()->create([
        'language_id' => $language->getKey(),
        'translatable_type' => $missingAlt->getMorphClass(),
        'translatable_id' => $missingAlt->getKey(),
        'title' => 'Needs alt text',
        'meta' => ['alt' => '', 'credit' => 'Capell Studio'],
    ]);
    Translation::query()->create([
        'language_id' => $language->getKey(),
        'translatable_type' => $healthy->getMorphClass(),
        'translatable_id' => $healthy->getKey(),
        'title' => 'Healthy image',
        'meta' => ['alt' => 'A healthy image', 'credit' => 'Capell Studio'],
    ]);
    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $healthy->getMorphClass(),
        'asset_id' => $healthy->getKey(),
        'order' => 1,
    ]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertTableColumnExists('health_state')
        ->assertTableFilterExists('health')
        ->assertTableBulkActionExists('mark-media-decorative')
        ->assertTableBulkActionExists('delete-unused-media')
        ->assertTableColumnStateSet('health_state', 'missing_alt', $missingAlt)
        ->filterTable('health', 'missing_alt')
        ->assertCanSeeTableRecords([$missingAlt])
        ->assertCanNotSeeTableRecords([$healthy]);
});

it('limits tracked media usage to accessible related records', function (): void {
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();
    $layout = Layout::factory()->createOne(['site_id' => null]);
    $media = CapellMedia::factory()->model($layout)->createOne([
        'name' => 'Global layout image',
        'file_name' => 'global-layout.jpg',
    ]);
    $hiddenPage = Page::factory()->site($hiddenSite)->createOne([
        'name' => 'Hidden dependent page',
    ]);
    $visiblePage = Page::factory()->site($assignedSite)->createOne([
        'name' => 'Visible dependent page',
    ]);

    AssetAttachment::query()->create([
        'related_type' => $hiddenPage->getMorphClass(),
        'related_id' => $hiddenPage->getKey(),
        'asset_type' => $media->getMorphClass(),
        'asset_id' => $media->getKey(),
        'order' => 1,
    ]);
    AssetAttachment::query()->create([
        'related_type' => $visiblePage->getMorphClass(),
        'related_id' => $visiblePage->getKey(),
        'asset_type' => $media->getMorphClass(),
        'asset_id' => $media->getKey(),
        'order' => 1,
    ]);

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    $livewire = Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$media])
        ->assertTableColumnStateSet('usage_count', 1, $media)
        ->filterTable('tracked_use', 'used')
        ->assertCanSeeTableRecords([$media]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('tracked_use', 'unused')
        ->assertCanNotSeeTableRecords([$media]);

    $usageColumn = $livewire->instance()->getTable()->getColumn('usage_count');

    expect($usageColumn)->not->toBeNull();

    $media = $livewire->instance()->getTableRecords()->firstWhere('id', $media->getKey());

    expect($media)->toBeInstanceOf(CapellMedia::class);

    $usageColumn->record($media);

    expect($usageColumn->getUrl($usageColumn->getState()))
        ->toBe(MediaResource::getUrl('edit', ['record' => $media]))
        ->and($usageColumn->getTooltip($usageColumn->getState()))
        ->toBe(trans_choice('capell-admin::table.asset_usage_count_tooltip', 1, ['count' => 1]));

    $usageItems = BuildMediaUsageItemsAction::run($media);

    expect($usageItems)
        ->toHaveCount(2)
        ->and(collect($usageItems)->pluck('title')->all())
        ->toContain($visiblePage->name)
        ->not->toContain($hiddenPage->name);
});

it('rejects a forged local path in bulk media uploads without reading or deleting it', function (string $path): void {
    $site = Site::factory()->createOne();
    Storage::disk('local')->put($path, 'private sentinel');

    Livewire::test(ListMedia::class)
        ->callAction('upload-files', ['site_id' => $site->getKey(), 'files' => [$path]])
        ->assertHasActionErrors(['files'])
        ->assertSet('uploadBatchId', null)
        ->unmountAction()
        ->assertActionHidden('retry-upload-files');

    expect(Storage::disk('local')->get($path))->toBe('private sentinel')
        ->and(CapellMedia::query()->count())->toBe(0);
})->with(['private/secret.txt', 'media-uploads/another-session.txt']);

it('rejects a forged replacement path and preserves the original media bytes', function (): void {
    $site = Site::factory()->createOne();
    $original = $site->addMedia(UploadedFile::fake()->createWithContent('original.txt', 'original bytes'))->toMediaCollection('uploads');
    Storage::disk('local')->put('private/secret.txt', 'private sentinel');

    Livewire::test(ListMedia::class)
        ->callTableAction('replace-file', $original, data: ['replacement' => ['private/secret.txt']])
        ->assertHasTableActionErrors(['replacement']);

    expect(Storage::disk('public')->get($original->getPathRelativeToRoot()))->toBe('original bytes')
        ->and(Storage::disk('local')->get('private/secret.txt'))->toBe('private sentinel');
});

it('requires media creation permission for both creation actions', function (string $action): void {
    $site = Site::factory()->createOne();
    Permission::findOrCreate('ViewAny:Media');
    Permission::findOrCreate('Create:Media');
    $user = test()->createUserWithPermission('ViewAny:Media');
    $user->assignedSiteIds = collect([$site->getKey()]);

    test()->actingAs($user);
    expect(Gate::allows('create', CapellMedia::class))->toBeFalse();

    Livewire::test(ListMedia::class)->mountAction($action)->assertActionNotMounted();
    Livewire::test(ListMedia::class)->call('mountAction', $action)->call('callMountedAction', [
        'name' => 'Forbidden video',
        'youtube_url' => 'https://youtu.be/FgalLC99jzY',
        'site_id' => $site->getKey(),
        'files' => [UploadedFile::fake()->image('forbidden.png')],
    ])->assertActionNotMounted();

    expect(CapellMedia::query()->count())->toBe(0);
})->with(['upload-files', 'add-youtube-video']);

it('honours a registered media resource that denies creation when the model policy allows it', function (string $action): void {
    $site = Site::factory()->createOne();
    expect(Gate::allows('create', CapellMedia::class))->toBeTrue();
    CapellAdmin::getAdminSurfaceRegistry()->replace(AdminSurfaceContributionData::resource(
        CreationDeniedMediaResource::class,
        'Media',
    ));

    try {
        expect(ListMedia::getResource())->toBe(CreationDeniedMediaResource::class);
        Livewire::test(ListMedia::class)->mountAction($action)->assertActionNotMounted();
        Livewire::test(ListMedia::class)->call('mountAction', $action)->call('callMountedAction', [
            'name' => 'Forbidden video',
            'youtube_url' => 'https://youtu.be/FgalLC99jzY',
            'site_id' => $site->getKey(),
            'files' => [UploadedFile::fake()->image('forbidden.png')],
        ])->assertActionNotMounted();

        expect(CapellMedia::query()->count())->toBe(0);
    } finally {
        CapellAdmin::getAdminSurfaceRegistry()->replace(AdminSurfaceContributionData::resource(MediaResource::class, 'Media'));
    }
})->with(['upload-files', 'add-youtube-video']);

it('recovers a batch when the second real temporary upload fails staging', function (string $operation): void {
    Exceptions::fake();
    $site = Site::factory()->createOne();
    config()->set('livewire.temporary_file_upload.disk', 'local');
    $local = Storage::disk('local');
    $failingLocal = Mockery::mock($local);
    $writes = 0;
    $failingLocal->shouldReceive($operation)->andReturnUsing(function (...$arguments) use ($local, &$writes, $operation): bool {
        if (str_contains($arguments[0], '.part.') && ++$writes === 2) {
            return false;
        }

        return $local->{$operation}(...$arguments);
    });
    Storage::set('local', $failingLocal);
    $page = Livewire::test(ListMedia::class)->callAction('upload-files', [
        'site_id' => $site->getKey(),
        'files' => [
            UploadedFile::fake()->createWithContent('first.txt', 'first bytes'),
            UploadedFile::fake()->createWithContent('second.txt', 'second bytes'),
            UploadedFile::fake()->createWithContent('third.txt', 'third bytes'),
        ],
    ])->assertHasNoActionErrors()->assertNotified();
    $id = $page->get('uploadBatchId');
    expect($id)->toBeString();
    $batch = expectPresent(UploadSiteMediaAction::make()->outcome($id));
    expect(array_column($batch->files, 'status'))->toBe([
        MediaUploadStatus::Unresolved, MediaUploadStatus::Failed, MediaUploadStatus::Unresolved,
    ])->and(CapellMedia::query()->count())->toBe(0)
        ->and($local->get($batch->files[0]->path))->toBe('first bytes')
        ->and(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($batch->files[0]->temporaryUpload)))->toBeFalse()
        ->and(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($batch->files[1]->temporaryUpload)))->toBeTrue()
        ->and(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($batch->files[2]->temporaryUpload)))->toBeTrue()
        ->and($local->allFiles('media-uploads'))->toHaveCount(1);

    Storage::set('local', $local);
    $page->unmountAction()->assertActionVisible('retry-upload-files')
        ->callAction('retry-upload-files')->assertHasNoActionErrors();
    $recovered = expectPresent(UploadSiteMediaAction::make()->outcome($id));
    expect($recovered->successfulCount())->toBe(3)
        ->and(CapellMedia::query()->count())->toBe(3)
        ->and($local->allFiles('media-uploads'))->toBe([]);
    foreach ($recovered->files as $file) {
        expect(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($file->temporaryUpload)))->toBeFalse();
    }

    foreach (['first', 'second', 'third'] as $name) {
        $media = CapellMedia::query()->where('name', $name)->sole();
        expect(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toBe($name . ' bytes');
    }

    $page->assertActionHidden('retry-upload-files');
    UploadSiteMediaAction::make()->retry($id);
    expect(CapellMedia::query()->count())->toBe(3);
})->with(['put', 'move']);

it('recovers staging even when the failure journal cannot be written', function (): void {
    Exceptions::fake();
    $site = Site::factory()->createOne();
    config()->set('livewire.temporary_file_upload.disk', 'local');
    $local = Storage::disk('local');
    $failingLocal = Mockery::mock($local);
    $writes = 0;
    $journalDirectory = $local->path('capell/media-upload-journal');
    $journalWriteFailed = false;
    $failingLocal->shouldReceive('put')->andReturnUsing(function (...$arguments) use ($local, &$writes, $journalDirectory): bool {
        if (str_contains($arguments[0], '.part.') && ++$writes === 2) {
            chmod($journalDirectory, 0500);

            return false;
        }

        return $local->put(...$arguments) !== false;
    });
    Storage::set('local', $failingLocal);
    $previousHandler = set_error_handler(function (int $severity, string $message, string $file, int $line) use (&$journalWriteFailed, &$previousHandler, $journalDirectory): bool {
        if (str_contains($message, 'file_put_contents(' . $journalDirectory . '/') && str_contains($message, 'Permission denied')) {
            $journalWriteFailed = true;

            return true;
        }

        return $previousHandler === null ? false : $previousHandler($severity, $message, $file, $line) !== false;
    });
    try {
        $page = Livewire::test(ListMedia::class)->callAction('upload-files', [
            'site_id' => $site->getKey(),
            'files' => [
                UploadedFile::fake()->createWithContent('first.txt', 'first bytes'),
                UploadedFile::fake()->createWithContent('second.txt', 'second bytes'),
                UploadedFile::fake()->createWithContent('third.txt', 'third bytes'),
            ],
        ])->assertHasNoActionErrors()->assertNotified();
    } finally {
        chmod($journalDirectory, 0700);
        restore_error_handler();
    }

    expect($journalWriteFailed)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The upload journal could not be saved.');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The temporary upload could not be stored.');
    $id = $page->get('uploadBatchId');
    expect($id)->toBeString();
    $batch = expectPresent(UploadSiteMediaAction::make()->outcome($id));
    expect(array_column($batch->files, 'status'))->toBe([
        MediaUploadStatus::Unresolved, MediaUploadStatus::Unresolved, MediaUploadStatus::Unresolved,
    ])->and(CapellMedia::query()->count())->toBe(0)
        ->and($local->get($batch->files[0]->path))->toBe('first bytes')
        ->and(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($batch->files[0]->temporaryUpload)))->toBeFalse()
        ->and(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($batch->files[1]->temporaryUpload)))->toBeTrue()
        ->and(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($batch->files[2]->temporaryUpload)))->toBeTrue()
        ->and($local->allFiles('media-uploads'))->toHaveCount(1);

    Storage::set('local', $local);
    $page->unmountAction()->assertActionVisible('retry-upload-files')
        ->callAction('retry-upload-files')->assertHasNoActionErrors();
    $recovered = expectPresent(UploadSiteMediaAction::make()->outcome($id));
    expect($recovered->successfulCount())->toBe(3)
        ->and(CapellMedia::query()->count())->toBe(3)
        ->and($local->allFiles('media-uploads'))->toBe([]);
    foreach ($recovered->files as $file) {
        expect(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($file->temporaryUpload)))->toBeFalse();
    }

    foreach (['first', 'second', 'third'] as $name) {
        $media = CapellMedia::query()->where('name', $name)->sole();
        expect(Storage::disk('public')->get($media->getPathRelativeToRoot()))->toBe($name . ' bytes');
    }

    $page->assertActionHidden('retry-upload-files');
    UploadSiteMediaAction::make()->retry($id);
    expect(CapellMedia::query()->count())->toBe(3);
});
