<?php

declare(strict_types=1);

namespace Capell\Core\Data\Media;

use Spatie\LaravelData\Data;

/**
 * An area of the source image, in preset pixel space, that should stay free of
 * important subject matter because the layout overlays text or controls there.
 */
final class MediaCompositionQuietRegionData extends Data
{
    public function __construct(
        public string $label,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    public function fitsWithin(int $width, int $height): bool
    {
        return $this->x >= 0
            && $this->y >= 0
            && $this->width > 0
            && $this->height > 0
            && $this->x + $this->width <= $width
            && $this->y + $this->height <= $height;
    }
}
