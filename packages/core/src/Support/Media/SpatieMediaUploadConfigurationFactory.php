<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Contracts\Media\MediaUploadConfigurationFactory;
use Capell\Core\Data\Media\MediaUploadConfigurationData;
use Capell\Core\Enums\MediaConversionEnum;

final class SpatieMediaUploadConfigurationFactory implements MediaUploadConfigurationFactory
{
    public function __construct(private readonly MediaCropPresetRepository $cropPresets) {}

    public function make(string $name): MediaUploadConfigurationData
    {
        return new MediaUploadConfigurationData(
            name: $name,
            collection: $name,
            responsiveImages: true,
            conversion: MediaConversionEnum::Thumbnail->value,
            panelLayout: 'grid',
            imageEditor: true,
            imageEditorMode: 2,
            aspectRatioOptions: $this->cropPresets->aspectRatioOptions(),
            disk: 'public',
        );
    }
}
