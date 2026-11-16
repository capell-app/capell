<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

/**
 * Render-ready description of a stored image: the public URL, its intrinsic size and,
 * when smaller derivatives exist, a srcset. It carries nothing but what the public
 * <img> needs, so it is safe to cache and to expose in public output.
 */
final class ResponsiveImageData extends Data
{
    public function __construct(
        public readonly string $src,
        public readonly int $width,
        public readonly int $height,
        public readonly ?string $srcset = null,
    ) {}
}
