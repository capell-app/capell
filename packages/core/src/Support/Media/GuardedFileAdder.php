<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Illuminate\Support\Facades\Storage;
use Override;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\RemoteFile;

/**
 * Applies the upload content policy to every file the media library stores.
 *
 * Form validation gives the person uploading a readable error first. This is the
 * boundary behind it: an import, a package or a forged request that reaches the
 * media library by another route is refused here before a record or a file exists.
 */
final class GuardedFileAdder extends FileAdder
{
    #[Override]
    protected function processMediaItem(HasMedia $model, Media $media, FileAdder $fileAdder): void
    {
        $policy = resolve(UploadContentPolicy::class);
        $fileName = (string) $media->file_name;

        if ($fileAdder->file instanceof RemoteFile) {
            $policy->assertStorableContents($fileName, strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'svg'
                ? (string) Storage::disk($fileAdder->file->getDisk())->get($fileAdder->file->getKey())
                : '');
        } else {
            $policy->assertStorable($fileName, $fileAdder->pathToFile);
        }

        parent::processMediaItem($model, $media, $fileAdder);
    }
}
