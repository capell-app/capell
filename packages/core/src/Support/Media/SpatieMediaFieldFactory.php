<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Contracts\Media\MediaFieldFactory;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Image\Image;

/**
 * Default Spatie-backed Filament compatibility adapter.
 *
 * @deprecated 1.x compatibility adapter. New UI integrations should consume
 *             MediaUploadConfigurationFactory and translate the data at their
 *             own UI boundary.
 */
final class SpatieMediaFieldFactory implements MediaFieldFactory
{
    public function __construct(private readonly MediaCropPresetRepository $cropPresets) {}

    public function make(string $name): SpatieMediaLibraryFileUpload
    {
        $configuration = (new SpatieMediaUploadConfigurationFactory($this->cropPresets))->make($name);

        return SpatieMediaLibraryFileUpload::make($name)
            ->collection($configuration->collection)
            ->when($configuration->responsiveImages, fn (SpatieMediaLibraryFileUpload $field): SpatieMediaLibraryFileUpload => $field->responsiveImages())
            ->conversion($configuration->conversion)
            ->panelLayout($configuration->panelLayout)
            ->when($configuration->imageEditor, fn (SpatieMediaLibraryFileUpload $field): SpatieMediaLibraryFileUpload => $field->imageEditor())
            ->imageEditorMode($configuration->imageEditorMode)
            ->imageEditorAspectRatioOptions($configuration->aspectRatioOptions)
            ->disk($configuration->disk)
            ->customProperties(function (TemporaryUploadedFile $file): array {
                $image = Image::load($file->getRealPath());

                return [
                    'height' => $image->getHeight(),
                    'width' => $image->getWidth(),
                ];
            });
    }
}
