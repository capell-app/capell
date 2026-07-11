<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\Core\Enums\MediaConversionEnum;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Image\Image;

/**
 * Default backend-agnostic Spatie-backed media field factory.
 *
 * Core keeps this factory free of admin concerns (no translations, no admin
 * actions). Admin wraps this with AdminSpatieMediaFieldFactory to add its
 * label + max-size on top.
 */
final class SpatieMediaFieldFactory implements MediaFieldFactory
{
    public function __construct(private readonly MediaCropPresetRepository $cropPresets) {}

    public function make(string $name): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make($name)
            ->collection(fn (SpatieMediaLibraryFileUpload $component): string => $component->getName())
            ->responsiveImages()
            ->conversion(MediaConversionEnum::Thumbnail->value)
            ->panelLayout('grid')
            ->imageEditor()
            ->imageEditorMode(2)
            ->imageEditorAspectRatioOptions(fn (): array => $this->cropPresets->aspectRatioOptions())
            ->disk('public')
            ->customProperties(function (TemporaryUploadedFile $file): array {
                $image = Image::load($file->getRealPath());

                return [
                    'height' => $image->getHeight(),
                    'width' => $image->getWidth(),
                ];
            });
    }
}
