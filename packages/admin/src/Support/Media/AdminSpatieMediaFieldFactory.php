<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Admin\Actions\GetMaxUploadSizeInBytes;
use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Core\Contracts\Media\MediaUploadConfigurationFactory;
use Capell\Core\Contracts\Media\MediaUploadMetadataResolver;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Admin-owned Filament adapter for Core's neutral media configuration.
 *
 * Adds admin-side concerns that core must not know about: the translated
 * label (capell-admin translation namespace) and the runtime max upload size.
 */
final class AdminSpatieMediaFieldFactory implements AdminMediaFieldFactory
{
    public function __construct(
        private readonly MediaUploadConfigurationFactory $configurations,
        private readonly MediaUploadMetadataResolver $metadata,
    ) {}

    public function make(string $name): SpatieMediaLibraryFileUpload
    {
        $configuration = $this->configurations->make($name);

        return SpatieMediaLibraryFileUpload::make($configuration->name)
            ->collection($configuration->collection)
            ->responsiveImages()
            ->conversion($configuration->conversion)
            ->panelLayout($configuration->panelLayout)
            ->imageEditor()
            ->imageEditorMode($configuration->imageEditorMode)
            ->imageEditorAspectRatioOptions($configuration->aspectRatioOptions)
            ->disk($configuration->disk)
            ->customProperties(fn (TemporaryUploadedFile $file): array => $this->metadata->resolve($file->getRealPath()))
            ->label(__('capell-admin::form.image'))
            ->maxSize(GetMaxUploadSizeInBytes::run());
    }
}
