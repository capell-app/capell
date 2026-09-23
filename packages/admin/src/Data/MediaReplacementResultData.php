<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MediaReplacementResultData extends Data
{
    public function __construct(
        public readonly Media $media,
        public readonly ?string $cleanupWarning = null,
    ) {}
}
