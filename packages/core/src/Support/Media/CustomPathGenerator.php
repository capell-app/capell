<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Illuminate\Support\Str;
use Override;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

class CustomPathGenerator extends DefaultPathGenerator
{
    #[Override]
    protected function getBasePath(Media $media): string
    {
        $prefix = config('media-library.prefix', 'media');

        $modelName = Str::kebab(class_basename($media->model_type));

        $name = Str::slug($media->name) . '-' . $media->getKey();

        $dir = DIRECTORY_SEPARATOR;

        return implode($dir, [$prefix, $media->collection_name, $modelName, $name]) . $dir;
    }
}
