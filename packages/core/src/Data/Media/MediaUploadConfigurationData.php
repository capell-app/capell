<?php

declare(strict_types=1);

namespace Capell\Core\Data\Media;

use Spatie\LaravelData\Data;

final class MediaUploadConfigurationData extends Data
{
    /**
     * @param  list<string|null>  $aspectRatioOptions
     */
    public function __construct(
        public string $name,
        public string $collection,
        public bool $responsiveImages,
        public string $conversion,
        public string $panelLayout,
        public bool $imageEditor,
        public int $imageEditorMode,
        public array $aspectRatioOptions,
        public string $disk,
    ) {}
}
