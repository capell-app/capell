<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Data\MediaReplacementFileData;
use Capell\Admin\Data\MediaReplacementResultData;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File as LocalFiles;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGenerator;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\File;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;
use Throwable;

/**
 * Stage bytes and conversions before replacing files at the original URLs.
 * The original row, filename, UUID and references survive success and rollback.
 *
 * @method static MediaReplacementResultData run(Media $media, string $absoluteFilePath)
 */
class ReplaceMediaFileAction
{
    use AsFake;
    use AsObject;

    public function handle(Media $media, string $absoluteFilePath): MediaReplacementResultData
    {
        $connection = $media->getConnection();
        $library = resolve(Filesystem::class);
        $workspaceId = 'capell-media-replacement-' . Str::uuid();
        $workspacePath = storage_path('app/private/capell-media-replacement/' . $workspaceId);
        $previousTemporaryDirectory = config('media-library.temporary_directory_path');
        $files = [];
        $retainRecoveryFiles = false;
        $transactionStarted = false;
        $failure = null;
        $cleanupWarning = null;
        $privateDisk = null;

        try {
            $connection->beginTransaction();
            $transactionStarted = true;
            $original = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $replacement = $this->validateReplacement($original, $absoluteFilePath);
            throw_unless(LocalFiles::makeDirectory($workspacePath, 0o700, true), RuntimeException::class, __('capell-admin::media.replacement_workspace_failed'));
            $this->assertPrivateWorkspace($original, $workspacePath);
            $diskConfiguration = [
                'driver' => 'local',
                'root' => $workspacePath,
                'visibility' => 'private',
                'directory_visibility' => 'private',
                'serve' => false,
                'throw' => true,
            ];
            config()->set('filesystems.disks.' . $workspaceId, $diskConfiguration);
            config()->set('media-library.temporary_directory_path', $workspacePath . '/converting');
            $privateDisk = Storage::disk($workspaceId);

            $staged = $original->replicate(['uuid']);
            $staged->uuid = Str::uuid()->toString();
            $staged->disk = $workspaceId;
            $staged->conversions_disk = $workspaceId;
            $staged->size = $replacement->size;
            $staged->mime_type = $replacement->mimeType;
            $staged->generated_conversions = [];
            $staged->responsive_images = [];
            if (str_starts_with($replacement->mimeType, 'image/')) {
                $dimensions = getimagesize($absoluteFilePath);

                if ($dimensions !== false) {
                    $staged->setCustomProperty('width', $dimensions[0]);
                    $staged->setCustomProperty('height', $dimensions[1]);
                }
            }

            throw_unless($staged->saveQuietly(), RuntimeException::class, __('capell-admin::media.replacement_metadata_failed'));
            $library->copyToMediaLibrary($absoluteFilePath, $staged, targetFileName: $original->file_name);
            $sourceChecksum = hash_file('sha256', $absoluteFilePath);
            $stagedChecksum = Storage::disk($staged->disk)->checksum($this->mediaDirectory($staged, $library, null) . $staged->file_name, ['checksum_algo' => 'sha256']);
            throw_if($sourceChecksum === false || $stagedChecksum === false || ! hash_equals($sourceChecksum, $stagedChecksum), RuntimeException::class, __('capell-admin::media.replacement_integrity_failed'));

            if (ImageGeneratorFactory::forMedia($staged) instanceof ImageGenerator) {
                $conversions = ConversionCollection::createForMedia($staged)
                    ->filter(fn (Conversion $conversion): bool => $conversion->shouldBePerformedOn($staged->collection_name));
                // Complete queued and deferred conversions before replacing originals
                // so conversion failures can retain the previous file set.
                resolve(FileManipulator::class)->performConversions($conversions, $staged);

                if ($original->hasResponsiveImages()) {
                    resolve(ResponsiveImageGenerator::class)->generateResponsiveImages($staged);
                }
            }

            $files = $this->replacementFiles($original, $staged, $library, $privateDisk);
            throw_unless($privateDisk->put('recovery.json', json_encode(
                array_map(static fn (MediaReplacementFileData $file): array => $file->toArray(), $files),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )), RuntimeException::class, __('capell-admin::media.replacement_backup_failed'));

            foreach ($files as $file) {
                if ($file->backupPath !== null) {
                    throw_unless($this->transfer(Storage::disk($file->disk), $file->path, $privateDisk, $file->backupPath), RuntimeException::class, __('capell-admin::media.replacement_backup_failed'));
                }
            }

            foreach ($files as $file) {
                $file->changed = true;
                $disk = Storage::disk($file->disk);
                $written = $file->stagedPath === null ? $disk->delete($file->path) : $this->transfer($privateDisk, $file->stagedPath, $disk, $file->path);
                throw_unless($written, RuntimeException::class, __('capell-admin::media.replacement_write_failed'));
            }

            $original->size = $staged->size;
            $original->mime_type = $staged->mime_type;
            $original->generated_conversions = $staged->generated_conversions;
            $original->responsive_images = $staged->responsive_images;
            $original->custom_properties = $staged->custom_properties;
            $original->setCustomProperty('replaced_at', now()->toIso8601String());
            throw_unless($original->save(), RuntimeException::class, __('capell-admin::media.replacement_metadata_failed'));
            throw_unless($staged->newQuery()->whereKey($staged->getKey())->forceDelete() === 1, RuntimeException::class, __('capell-admin::media.replacement_metadata_failed'));
            $connection->commit();
            $transactionStarted = false;
        } catch (Throwable $throwable) {
            $failure = $throwable;

            if ($transactionStarted) {
                try {
                    $connection->rollBack();
                } catch (Throwable $rollbackFailure) {
                    // Database recovery must not prevent file recovery or discard
                    // the private backups when the database outcome is uncertain.
                    $retainRecoveryFiles = true;
                    try {
                        // A non-lost PDO failure leaves Laravel's transaction level
                        // unchanged, so the uncertain connection must not be reused.
                        $connection->disconnect();
                    } catch (Throwable $disconnectFailure) {
                        $connection->setPdo(null)->setReadPdo(null)->setDirectPdo(null);
                        $this->reportFailure(__('capell-admin::media.replacement_recovery_required', ['path' => $workspacePath]), $disconnectFailure);
                    }

                    $this->reportFailure(__('capell-admin::media.replacement_recovery_required', ['path' => $workspacePath]), $rollbackFailure);
                }
            }

            foreach (array_reverse($files) as $file) {
                if (! $file->changed) {
                    continue;
                }

                if ($privateDisk === null) {
                    continue;
                }

                try {
                    $disk = Storage::disk($file->disk);
                    $restored = $file->backupPath === null ? $disk->delete($file->path) : $this->transfer($privateDisk, $file->backupPath, $disk, $file->path);
                } catch (Throwable) {
                    $restored = false;
                }

                $retainRecoveryFiles = ! $restored || $retainRecoveryFiles;
            }

            if ($retainRecoveryFiles) {
                $this->reportFailure(__('capell-admin::media.replacement_recovery_required', ['path' => $workspacePath]), $throwable);
            }

            throw $throwable;
        } finally {
            try {
                if (! $retainRecoveryFiles && LocalFiles::isDirectory($workspacePath)) {
                    throw_unless(LocalFiles::deleteDirectory($workspacePath), RuntimeException::class, __('capell-admin::media.replacement_cleanup_failed', ['paths' => $workspacePath]));
                }
            } catch (Throwable $cleanupFailure) {
                // Cleanup cannot undo a committed replacement or replace the
                // original failure. Retain its diagnostic as a warning/context.
                $cleanupWarning = Lang::string('capell-admin::media.replacement_cleanup_failed', ['paths' => $workspacePath]);
                $this->reportFailure($cleanupWarning, $failure ?? $cleanupFailure);
            } finally {
                Storage::forgetDisk($workspaceId);
                $diskConfigurations = config('filesystems.disks');
                if (is_array($diskConfigurations)) {
                    unset($diskConfigurations[$workspaceId]);
                    config()->set('filesystems.disks', $diskConfigurations);
                }

                config()->set('media-library.temporary_directory_path', $previousTemporaryDirectory);
            }
        }

        return new MediaReplacementResultData($original, $cleanupWarning);
    }

    private function validateReplacement(Media $media, string $path): File
    {
        throw_unless(is_file($path) && is_readable($path), RuntimeException::class, __('capell-admin::media.replacement_unreadable'));
        $mime = mime_content_type($path);
        $size = filesize($path);
        throw_if($mime === false || $size === false || $size === 0, RuntimeException::class, __('capell-admin::media.replacement_unreadable'));
        // Static servers infer Content-Type from the stable filename. A type
        // change needs a new asset instead of silently changing that URL contract.
        throw_if($media->mime_type !== null && $media->mime_type !== $mime, RuntimeException::class, __('capell-admin::media.replacement_type_mismatch'));
        $maximumSize = config('media-library.max_file_size');
        throw_if(is_int($maximumSize) && $size > $maximumSize, RuntimeException::class, __('capell-admin::media.replacement_too_large'));
        $file = new File(basename($path), $size, $mime);
        $owner = $media->model;
        throw_unless($owner instanceof HasMedia, RuntimeException::class, __('capell-admin::media.replacement_owner_missing'));
        $collection = $owner->getMediaCollection($media->collection_name);

        if ($collection instanceof MediaCollection) {
            throw_if(! ($collection->acceptsFile)($file, $owner)
                || ($collection->acceptsMimeTypes !== [] && ! in_array($mime, $collection->acceptsMimeTypes, true)), RuntimeException::class, __('capell-admin::media.replacement_not_accepted'));
        }

        return $file;
    }

    private function assertPrivateWorkspace(Media $original, string $workspacePath): void
    {
        $roots = [public_path()];

        foreach (array_unique([$original->disk, $original->conversions_disk ?: $original->disk]) as $diskName) {
            $configuration = Storage::disk($diskName)->getConfig();
            if (($configuration['driver'] ?? null) === 'local' && is_string($configuration['root'] ?? null)) {
                $roots[] = $configuration['root'];
            }
        }

        $workspace = realpath($workspacePath);
        throw_if($workspace === false, RuntimeException::class, __('capell-admin::media.replacement_workspace_failed'));

        foreach ($roots as $root) {
            $resolved = realpath($root);
            throw_if($resolved !== false && ($workspace === $resolved || str_starts_with($workspace, $resolved . DIRECTORY_SEPARATOR)), RuntimeException::class, __('capell-admin::media.replacement_shared_path'));
        }
    }

    private function transfer(FilesystemAdapter $source, string $sourcePath, FilesystemAdapter $destination, string $destinationPath): bool
    {
        $stream = $source->readStream($sourcePath);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            return $destination->put($destinationPath, $stream) === true;
        } finally {
            fclose($stream);
        }
    }

    private function reportFailure(string $message, Throwable $previous): void
    {
        try {
            report(new RuntimeException($message, previous: $previous));
        } catch (Throwable $throwable) {
            // A broken reporting transport must not turn completed work into a
            // retryable failure, nor hide the exception that caused rollback.
            error_log($message . ' ' . $throwable->getMessage());
        }
    }

    private function mediaDirectory(Media $media, Filesystem $library, ?string $type): string
    {
        // Flysystem listings normalise leading and repeated separators, while
        // custom path generators may retain them. Slice paths in one format.
        return implode('/', array_filter(
            explode('/', str_replace('\\', '/', $library->getMediaDirectory($media, $type))),
            static fn (string $segment): bool => $segment !== '',
        )) . '/';
    }

    /** @return list<MediaReplacementFileData> */
    private function replacementFiles(Media $original, Media $staged, Filesystem $library, FilesystemAdapter $privateDisk): array
    {
        $files = [];

        foreach ([null, 'conversions', 'responsiveImages'] as $type) {
            $diskName = $type === null ? $original->disk : ($original->conversions_disk ?: $original->disk);
            $disk = Storage::disk($diskName);
            $originalDirectory = $this->mediaDirectory($original, $library, $type);
            $stagedDirectory = $this->mediaDirectory($staged, $library, $type);

            $originalPaths = $type === null ? [$originalDirectory . $original->file_name] : $disk->allFiles($originalDirectory);
            $stagedPaths = $type === null ? [$stagedDirectory . $staged->file_name] : $privateDisk->allFiles($stagedDirectory);

            foreach ($originalPaths as $path) {
                if (! $disk->exists($path)) {
                    continue;
                }

                $key = $diskName . ':' . $path;
                $files[$key] ??= new MediaReplacementFileData($diskName, $path, null, 'backups/' . hash('sha256', $key));
            }

            foreach ($stagedPaths as $stagedPath) {
                $path = $originalDirectory . substr($stagedPath, strlen($stagedDirectory));
                $files[$diskName . ':' . $path] = new MediaReplacementFileData(
                    $diskName,
                    $path,
                    $stagedPath,
                    $disk->exists($path) ? 'backups/' . hash('sha256', $diskName . ':' . $path) : null,
                );
            }
        }

        return array_values($files);
    }
}
