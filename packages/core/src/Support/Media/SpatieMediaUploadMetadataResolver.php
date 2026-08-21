<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Contracts\Media\MediaUploadMetadataResolver;
use Spatie\Image\Image;

final class SpatieMediaUploadMetadataResolver implements MediaUploadMetadataResolver
{
    /** @return array{height: int, width: int} */
    public function resolve(string $path): array
    {
        $image = Image::load($path);

        return [
            'height' => $image->getHeight(),
            'width' => $image->getWidth(),
        ];
    }
}
