<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Enums\ImageSourceType;
use Spatie\LaravelData\Data;

final class ImageSourceData extends Data
{
    public function __construct(
        public readonly ImageSourceType $type,
        public readonly ?string $url = null,
        public readonly ?string $path = null,
        public readonly ?MediaContract $media = null,
        public readonly ?string $alt = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {}

    public function isRenderable(): bool
    {
        if ($this->media instanceof MediaContract) {
            return true;
        }

        return is_string($this->url) && $this->url !== '';
    }
}
