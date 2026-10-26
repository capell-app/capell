<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Data\Media\MediaUploadAttemptData;
use Capell\Admin\Data\Media\MediaUploadBatchData;
use Capell\Admin\Data\Media\MediaUploadFileData;
use Capell\Admin\Enums\MediaUploadStatus;
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use Capell\Core\Support\Media\CustomPathGenerator;
use Capell\Core\Support\Permissions\SiteAccess;
use Closure;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Override;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

final class UploadSiteMediaAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, string> $originalFileNames */
    public function handle(Site $site, mixed $uploadedFiles, array $originalFileNames = []): MediaUploadBatchData
    {
        return $this->retry($this->prepare($site, $uploadedFiles, $originalFileNames)->id);
    }

    /** @param array<string, string> $originalFileNames */
    public function prepare(Site $site, mixed $uploadedFiles, array $originalFileNames = []): MediaUploadBatchData
    {
        $this->authorize($site);
        $paths = collect(is_array($uploadedFiles) ? $uploadedFiles : [])
            ->flatten()
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            throw ValidationException::withMessages([
                'files' => __('capell-admin::media.upload_files_required'),
            ]);
        }

        return $this->reserve($site, array_values($paths->map(fn (string $path): MediaUploadFileData => new MediaUploadFileData(
            path: $path,
            name: basename($originalFileNames[$path] ?? $path),
        ))->all()));
    }

    /** Reserve the entire snapshot before Filament stores any temporary file. */
    public function prepareFormSubmission(Site $site, mixed $uploadedFiles): MediaUploadBatchData
    {
        $this->authorize($site);
        $files = [];
        foreach (is_array($uploadedFiles) ? $uploadedFiles : [] as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $identity = $file->getFilename();
                $files['temporary:' . $identity] = new MediaUploadFileData(
                    path: $identity,
                    name: basename($file->getClientOriginalName()),
                    temporaryUpload: $identity,
                );
            } elseif (is_string($file) && $file !== '') {
                $files['stored:' . $file] = new MediaUploadFileData($file, basename($file));
            }
        }

        if ($files === []) {
            throw ValidationException::withMessages(['files' => __('capell-admin::media.upload_files_required')]);
        }

        return $this->reserve($site, array_values($files));
    }

    public function storeTemporaryUpload(string $id, TemporaryUploadedFile $upload): ?string
    {
        return Cache::lock($this->cacheKey($id) . ':lock', 600)->block(5, function () use ($id, $upload): string {
            $batch = $this->outcome($id);
            abort_unless($batch instanceof MediaUploadBatchData, 403);
            $site = SiteAccess::current()->query(Site::class)->findOrFail($batch->siteId);
            $this->authorize($site);
            $this->requireOutermostCommit($site);

            foreach ($batch->files as $index => $file) {
                if ($file->temporaryUpload !== $upload->getFilename()) {
                    continue;
                }

                // A replay must neither copy again nor recreate a source already
                // consumed by a committed upload.
                if ($file->status === MediaUploadStatus::Successful || Storage::disk('local')->exists($file->path)) {
                    return $file->path;
                }

                try {
                    $this->stageTemporaryUpload($file, $upload);
                } catch (Throwable $exception) {
                    try {
                        $this->remember($batch->withFile($index, new MediaUploadFileData(
                            $file->path,
                            $file->name,
                            MediaUploadStatus::Failed,
                            'capell-admin::media.upload_file_failed',
                            temporaryUpload: $file->temporaryUpload,
                        )));
                    } catch (Throwable $checkpointException) {
                        // The reservation already contains every source identity.
                        // A full disk must not hide recovery or replace the staging error.
                        report($checkpointException);
                    }

                    throw $exception;
                }

                return $file->path;
            }

            abort(403);
        });
    }

    public function outcome(string $id): ?MediaUploadBatchData
    {
        // The private journal is authoritative. A stale cache value must never
        // discard a destination checkpoint from an interrupted transaction.
        $path = $this->journalPath($id);
        $batch = is_file($path)
            ? MediaUploadBatchData::from(json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR))
            : Cache::get($this->cacheKey($id));

        return $batch instanceof MediaUploadBatchData && $batch->id === $id && hash_equals($batch->ownerScope, $this->ownerScope())
            ? $batch
            : null;
    }

    public function retry(string $id): MediaUploadBatchData
    {
        // Read the latest server checkpoint under the lock: stale/replayed UI
        // requests must never turn an already successful file into a new upload.
        return Cache::lock($this->cacheKey($id) . ':lock', 600)->block(5, function () use ($id): MediaUploadBatchData {
            $batch = $this->outcome($id);
            abort_unless($batch instanceof MediaUploadBatchData, 403);
            $site = SiteAccess::current()->query(Site::class)->findOrFail($batch->siteId);
            $this->authorize($site);
            $this->requireOutermostCommit($site);

            foreach ($batch->files as $index => $file) {
                if ($file->status === MediaUploadStatus::Successful) {
                    continue;
                }

                // Recheck live grants between files as well as on every retry.
                $this->authorize($site);
                $connection = $site->getConnection();
                $transactionLevel = $connection->transactionLevel();
                try {
                    $media = $connection->transaction(function () use ($site, &$batch, &$file, $index): Media {
                        // Serialise durable reconciliation and compensation with
                        // writers even if the cache lock's lease expires.
                        SiteAccess::current()->scope($site->newQuery())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
                        $this->authorize($site);
                        $committed = $this->committedMedia($site, $batch, $file);
                        if ($committed instanceof Media) {
                            return $committed;
                        }

                        // A previous request may have stopped after writing files
                        // but before saving its outcome. Reconcile before I/O.
                        if ($file->attempt instanceof MediaUploadAttemptData) {
                            $this->cleanAttempt($file->attempt);
                        }

                        if ($file->temporaryUpload !== null && ! Storage::disk('local')->exists($file->path)) {
                            throw_unless(FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($file->temporaryUpload)), RuntimeException::class, 'The temporary upload is no longer available.');
                            $this->stageTemporaryUpload($file, TemporaryUploadedFile::createFromLivewire($file->temporaryUpload));
                        }

                        return $this->storeFile($site, $batch, $file, function (SpatieMedia $media) use (&$batch, &$file, $index): void {
                            $attempt = $this->trackAttempt($media);
                            $file = new MediaUploadFileData($file->path, $file->name, MediaUploadStatus::ReconciliationRequired, 'capell-admin::media.upload_reconciliation_required', attempt: $attempt, temporaryUpload: $file->temporaryUpload);
                            $batch = $batch->withFile($index, $file);
                            // Persist the destinations before Spatie copies the
                            // original or writes any derived files.
                            $this->remember($batch);
                        });
                    });

                    $batch = $batch->withFile($index, $this->successfulFile($file, $media));
                } catch (Throwable $exception) {
                    report($exception);
                    $outcome = new MediaUploadFileData($file->path, $file->name, MediaUploadStatus::ReconciliationRequired, 'capell-admin::media.upload_reconciliation_required', attempt: $file->attempt, temporaryUpload: $file->temporaryUpload);
                    try {
                        throw_if($connection->transactionLevel() !== $transactionLevel, fn (): RuntimeException => new RuntimeException('The upload transaction has not confirmed rollback.', 0, $exception));

                        $outcome = $connection->transaction(function () use ($site, $batch, $file): MediaUploadFileData {
                            SiteAccess::current()->scope($site->newQuery())->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
                            // A post-commit listener can throw after the media is
                            // live. Never compensate a committed upload.
                            $committed = $this->committedMedia($site, $batch, $file);
                            if ($committed instanceof Media) {
                                return $this->successfulFile($file, $committed);
                            }

                            if ($file->attempt instanceof MediaUploadAttemptData) {
                                $this->cleanAttempt($file->attempt);
                            }

                            return new MediaUploadFileData($file->path, $file->name, MediaUploadStatus::Failed, 'capell-admin::media.upload_file_failed', temporaryUpload: $file->temporaryUpload);
                        });
                    } catch (Throwable $reconciliationException) {
                        // Unknown commit/cleanup state is an explicit checkpoint,
                        // retaining the source and destinations for reconciliation.
                        report($reconciliationException);
                    }

                    $batch = $batch->withFile($index, $outcome);
                    $this->remember($batch);

                    return $batch;
                }

                $this->remember($batch);
                try {
                    throw_unless(Storage::disk('local')->delete($file->path), RuntimeException::class, 'The committed upload source could not be removed.');
                    if ($file->temporaryUpload !== null && FileUploadConfiguration::storage()->exists(FileUploadConfiguration::path($file->temporaryUpload))) {
                        throw_unless(TemporaryUploadedFile::createFromLivewire($file->temporaryUpload)->delete(), RuntimeException::class, 'The committed temporary upload could not be removed.');
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $batch = $batch->withFile($index, new MediaUploadFileData($file->path, $file->name, MediaUploadStatus::Successful, 'capell-admin::media.upload_source_cleanup_failed', (int) $media->getKey(), temporaryUpload: $file->temporaryUpload));
                    $this->remember($batch);
                }
            }

            return $batch;
        });
    }

    /** @param list<MediaUploadFileData> $files */
    private function reserve(Site $site, array $files): MediaUploadBatchData
    {
        $this->requireOutermostCommit($site);
        // Server-side form entry points may precede session middleware. Capture
        // ownership only after its CSRF token is stable for upload grants too.
        if (! session()->isStarted()) {
            session()->start();
        }

        $ownerScope = $this->ownerScope();
        $identities = array_map(static fn (MediaUploadFileData $file): string => $file->temporaryUpload === null ? $file->path : 'temporary:' . $file->temporaryUpload, $files);
        sort($identities);
        $id = hash('sha256', serialize([$ownerScope, $site->getKey(), $identities]));

        return Cache::lock($this->cacheKey($id) . ':lock', 600)->block(5, function () use ($id, $site, $ownerScope, $files): MediaUploadBatchData {
            $existing = $this->outcome($id);
            if ($existing instanceof MediaUploadBatchData) {
                return $existing;
            }

            foreach ($files as $index => $file) {
                if ($file->temporaryUpload === null) {
                    continue;
                }

                $extension = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($file->name, PATHINFO_EXTENSION));
                $files[$index] = new MediaUploadFileData(
                    path: 'media-uploads/' . hash('sha256', $id . ':' . $file->temporaryUpload) . ($extension === '' ? '' : '.' . $extension),
                    name: $file->name,
                    temporaryUpload: $file->temporaryUpload,
                );
            }

            $batch = new MediaUploadBatchData(
                id: $id,
                siteId: (int) $site->getKey(),
                ownerScope: $ownerScope,
                files: $files,
            );
            $this->remember($batch);

            return $batch;
        });
    }

    /** @param Closure(SpatieMedia): void $track */
    private function storeFile(Site $site, MediaUploadBatchData $batch, MediaUploadFileData $file, Closure $track): Media
    {
        // Scope tracking to this FileAdder; conversion services and unrelated
        // uploads keep their existing container bindings.
        $filesystem = new class(resolve(Factory::class), $track) extends Filesystem
        {
            public function __construct(Factory $filesystem, private readonly Closure $track)
            {
                parent::__construct($filesystem);
            }

            #[Override]
            public function add(string $file, SpatieMedia $media, ?string $targetFileName = null): bool
            {
                ($this->track)($media);

                return parent::add($file, $media, $targetFileName);
            }
        };
        /** @var FileAdder<Media> $adder */
        $adder = app()->makeWith(FileAdder::class, ['filesystem' => $filesystem]);

        return $adder->setSubject($site)
            ->setFile(Storage::disk('local')->path($file->path))
            ->preservingOriginal()
            ->usingName(pathinfo($file->name, PATHINFO_FILENAME))
            ->withCustomProperties([
                'upload_batch' => $batch->id,
                'upload_file' => hash('sha256', $file->path),
            ])
            ->toMediaCollection('uploads', config('media-library.disk_name', 'public'));
    }

    private function trackAttempt(SpatieMedia $media): MediaUploadAttemptData
    {
        $paths = PathGeneratorFactory::create($media);
        // These generators allocate directories by media identity. Arbitrary
        // layouts cannot establish exclusive ownership before conversion I/O.
        throw_unless(in_array($paths::class, [DefaultPathGenerator::class, CustomPathGenerator::class], true), RuntimeException::class, 'The upload destination is not exclusive to this media.');
        $destinations = [];
        foreach ([
            [$media->disk, $paths->getPath($media)],
            [$media->conversions_disk, $paths->getPathForConversions($media)],
            [$media->conversions_disk, $paths->getPathForResponsiveImages($media)],
        ] as [$disk, $directory]) {
            $directory = trim($directory, '/');
            $this->requireExclusiveDirectory($directory, (int) $media->getKey());

            $destinations[] = [
                'disk' => $disk,
                'directory' => $directory,
                'existingFiles' => array_values(Storage::disk($disk)->allFiles($directory)),
            ];
        }

        // Never overwrite a file that compensation would have to restore.
        throw_if(Storage::disk($media->disk)->exists($paths->getPath($media) . $media->file_name), RuntimeException::class, 'The upload destination already exists.');

        return new MediaUploadAttemptData((int) $media->getKey(), $destinations, $paths::class);
    }

    private function cleanAttempt(MediaUploadAttemptData $attempt): void
    {
        // Check the captured identity too: a surviving row always owns its files.
        throw_if(Media::query()->withTrashed()->useWritePdo()->whereKey($attempt->mediaId)->exists(), RuntimeException::class, 'The upload destination still has a media owner.');

        // Only newly captured, allow-listed generators establish ownership.
        // Older journals have no provenance: an ID in a shared directory's name
        // cannot prove that its contents belong to this attempt.
        throw_unless(in_array($attempt->pathGenerator, [DefaultPathGenerator::class, CustomPathGenerator::class], true), RuntimeException::class, 'The upload destination is not exclusive to this media.');

        // Validate every directory before deleting anything.
        foreach ($attempt->destinations as $destination) {
            $this->requireExclusiveDirectory($destination['directory'], $attempt->mediaId);
        }

        foreach ($attempt->destinations as $destination) {
            $disk = Storage::disk($destination['disk']);
            foreach (array_diff($disk->allFiles($destination['directory']), $destination['existingFiles']) as $path) {
                throw_if(! $disk->delete($path) || $disk->exists($path), RuntimeException::class, 'The failed upload destination could not be removed.');
            }
        }
    }

    private function requireOutermostCommit(Site $site): void
    {
        throw_if($site->getConnection()->transactionLevel() !== 0, RuntimeException::class, 'Media uploads require an outermost commit.');
    }

    private function requireExclusiveDirectory(string $directory, int $mediaId): void
    {
        throw_if(
            in_array('..', explode('/', $directory), true)
            || preg_match('#(?:^|/)(?:[^/]+-)?' . $mediaId . '(?:/|$)#D', $directory) !== 1,
            RuntimeException::class,
            'The upload destination is not exclusive to this media.',
        );
    }

    private function stageTemporaryUpload(MediaUploadFileData $file, TemporaryUploadedFile $upload): void
    {
        $stream = $upload->readStream();
        throw_unless(is_resource($stream), RuntimeException::class, 'The temporary upload could not be read.');
        $temporary = $file->path . '.part.' . bin2hex(random_bytes(6));
        try {
            throw_if(! Storage::disk('local')->put($temporary, $stream)
                || ! Storage::disk('local')->move($temporary, $file->path), RuntimeException::class, 'The temporary upload could not be stored.');
        } finally {
            fclose($stream);
            Storage::disk('local')->delete($temporary);
        }
    }

    private function authorize(Site $site): void
    {
        Gate::authorize('create', Media::class);
        abort_unless(SiteAccess::current()->can($site), 403);
    }

    private function committedMedia(Site $site, MediaUploadBatchData $batch, MediaUploadFileData $file): ?Media
    {
        /** @var Media|null $media */
        $media = SiteAccess::current()->query(Media::class)->withTrashed()->useWritePdo()
            ->where('model_type', $site->getMorphClass())
            ->where('model_id', $site->getKey())
            ->where('collection_name', 'uploads')
            ->where('custom_properties->upload_batch', $batch->id)
            ->where('custom_properties->upload_file', hash('sha256', $file->path))
            ->first();

        return $media;
    }

    private function successfulFile(MediaUploadFileData $file, Media $media): MediaUploadFileData
    {
        return new MediaUploadFileData($file->path, $file->name, MediaUploadStatus::Successful, 'capell-admin::media.upload_file_successful', (int) $media->getKey(), temporaryUpload: $file->temporaryUpload);
    }

    private function remember(MediaUploadBatchData $batch): void
    {
        // Match Core's atomic manifest writes. This private storage checkpoint
        // is independent of the media transaction and has no cache TTL. Keep the
        // reservation after reconciliation for snapshot replay; only confirmed
        // cleanup/commit clears an attempt and its destinations from the journal.
        $path = $this->journalPath($batch->id);
        $directory = dirname($path);
        throw_if(! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory), RuntimeException::class, 'The upload journal directory could not be created.');

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $batch->toJson(JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The upload journal could not be saved.');
        }

        throw_unless(Cache::put($this->cacheKey($batch->id), $batch, now()->addMinutes((int) config('session.lifetime', 120))), RuntimeException::class, 'The upload checkpoint could not be saved.');
    }

    private function journalPath(string $id): string
    {
        // Hash caller input too, so retry IDs can never traverse storage paths.
        return Storage::disk('local')->path('capell/media-upload-journal/' . hash('sha256', $id) . '.json');
    }

    private function cacheKey(string $id): string
    {
        return 'capell:media-upload:' . $id;
    }

    private function ownerScope(): string
    {
        $actor = auth()->user();

        return hash('sha256', serialize([
            $actor === null ? null : $actor::class,
            $actor?->getAuthIdentifier(),
            session()->getId(),
            session()->token(),
        ]));
    }
}
