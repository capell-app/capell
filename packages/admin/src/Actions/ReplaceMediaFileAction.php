<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Replaces the underlying file of a Spatie Media Library record while
 * preserving the media row's ID and all relations that reference it.
 *
 * Strategy: add the replacement from an absolute path in the same collection,
 * transfer the original UUID and metadata, then delete the stale record.
 * This keeps the URL path stable for any links that embed the UUID.
 */
class ReplaceMediaFileAction
{
    use AsFake;
    use AsObject;

    public function handle(Media $media, string $absoluteFilePath): Media
    {
        /** @var Model&HasMedia $owner */
        $owner = $media->model;

        $originalUuid = $media->uuid;
        $originalOrder = $media->order_column;
        $originalCustomProperties = $media->custom_properties;

        $newMedia = $owner->addMedia($absoluteFilePath)
            ->preservingOriginal()
            ->usingName($media->name)
            ->toMediaCollection($media->collection_name, $media->disk);

        // Transfer the original UUID so existing URL references remain valid,
        // and record the replacement timestamp in custom properties.
        // Hard-delete the old record before saving the replacement with its UUID.
        // The Media model uses SoftDeletes, so a plain delete() would leave the
        // row (and its UUID) in place and trip the UUID unique constraint when the
        // replacement claims the same UUID below.
        $media->forceDelete();

        $newMedia->uuid = $originalUuid;
        $newMedia->order_column = $originalOrder;
        $newMedia->custom_properties = array_merge(
            $originalCustomProperties,
            ['replaced_at' => now()->toIso8601String()],
        );
        $newMedia->save();

        return $newMedia;
    }
}
