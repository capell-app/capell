<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Support\Media\AdminUploadBuilderTypes;
use Capell\Admin\Support\Media\AdminUploadDeletion;
use Capell\Admin\Support\Media\AdminUploadOwnership;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Component;
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
        $storedPaths = [];
        if (! $upload instanceof SpatieMediaLibraryFileUpload) {
            resolve(AdminUploadDeletion::class)->prepare($upload);
            $ownership = resolve(AdminUploadOwnership::class);
            $ownership->forgetMissingPaths($upload);
            $storedPaths = $ownership->paths($upload);
        }

        $record = $upload->getRecord();

        if (! $record instanceof Model) {
            return $storedPaths;
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
            $root = $upload->getModelRootContainer();
            $fieldPath = $upload->getStatePath();
            if (! is_string($fieldPath) || $fieldPath === '') {
                return [];
            }

            $segments = explode('.', $fieldPath);
            $container = $upload->getContainer();
            $builderTypes = resolve(AdminUploadBuilderTypes::class)->forUpload($upload);
            if ($builderTypes === null) {
                return [];
            }

            while ($container !== $root && ($parent = $container->getParentComponent()) instanceof Component) {
                if ($parent instanceof Repeater || $parent instanceof Builder) {
                    // Filament's UUID item keys are transient and reorderable.
                    // Authorise only this field's persisted paths across the
                    // persisted item snapshot, never constantState/current item data.
                    $itemsPath = $parent->getStatePath();
                    if (! is_string($itemsPath) || $itemsPath === '') {
                        return [];
                    }

                    $index = count(explode('.', $itemsPath));
                    $segments[$index] = '*';
                }

                $container = $parent->getContainer();
            }

            $rootPath = $root->getStatePath();
            $rootDepth = filled($rootPath) ? count(explode('.', $rootPath)) : 0;
            $path = array_slice($segments, $rootDepth);

            if ($path === []) {
                return [];
            }

            $paths = $this->resolvePaths($record->getOriginal(), $path, $builderTypes, $rootDepth);
        }

        return array_values(array_filter([...$paths, ...$storedPaths], static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }

    /**
     * @param  list<string>  $segments
     * @param  array<int, string>  $builderTypes
     * @return list<mixed>
     */
    private function resolvePaths(mixed $data, array $segments, array $builderTypes, int $depth): array
    {
        if ($segments === []) {
            return array_values(Arr::flatten(Arr::wrap($data)));
        }

        $segment = array_shift($segments);
        if ($segment !== '*') {
            return $this->resolvePaths(data_get($data, $segment), $segments, $builderTypes, $depth + 1);
        }

        if (! is_iterable($data)) {
            return [];
        }

        $paths = [];
        foreach ($data as $item) {
            if (isset($builderTypes[$depth]) && data_get($item, 'type') !== $builderTypes[$depth]) {
                continue;
            }

            array_push($paths, ...$this->resolvePaths($item, $segments, $builderTypes, $depth + 1));
        }

        return $paths;
    }
}
