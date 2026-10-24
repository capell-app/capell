<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ResolveAdminUploadOriginalPathsAction
{
    use AsFake;
    use AsObject;

    /** @return list<string> */
    public function handle(BaseFileUpload $upload): array
    {
        $record = $upload->getRecord();

        if (! $record instanceof Model) {
            return [];
        }

        if ($upload instanceof SpatieMediaLibraryFileUpload) {
            if (! $record instanceof HasMedia) {
                return [];
            }

            // Match server-side relationship hydration, including collection,
            // filter and single-file scope. Media fields contain UUIDs, not paths.
            $media = $record->getMedia($upload->getCollection() ?? 'default');

            if ($upload->hasMediaFilter()) {
                $media = $upload->filterMedia($media);
            }

            if (! $upload->isMultiple()) {
                $media = $media->take(1);
            }

            $paths = $media->map(fn (Media $item): mixed => $item->getAttribute('uuid'))->all();
        } else {
            $path = $upload->getStatePathForRelationship();

            if (! is_string($path) || $path === '') {
                return [];
            }

            // Read persisted values without accessing absent virtual attributes
            // or trusting client-controlled Livewire state.
            $paths = Arr::wrap(data_get($record->getOriginal(), $path));
        }

        return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }
}
