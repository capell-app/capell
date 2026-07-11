<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Admin\Actions\GetMaxUploadSizeInBytes;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\Core\Support\Media\SpatieMediaFieldFactory;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

/**
 * Admin decorator on top of the core SpatieMediaFieldFactory.
 *
 * Adds admin-side concerns that core must not know about: the translated
 * label (capell-admin translation namespace) and the runtime max upload
 * size action. Admin's service provider binds MediaFieldFactory to this
 * class so MediaLibraryFileUpload::make() picks up these extras.
 */
final class AdminSpatieMediaFieldFactory implements MediaFieldFactory
{
    public function __construct(private readonly SpatieMediaFieldFactory $inner) {}

    public function make(string $name): SpatieMediaLibraryFileUpload
    {
        return $this->inner->make($name)
            ->label(__('capell-admin::form.image'))
            ->maxSize(GetMaxUploadSizeInBytes::run());
    }
}
