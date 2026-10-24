<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Actions\Media\ResolveAdminUploadOriginalPathsAction;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload as FilamentSpatieMediaLibraryFileUpload;
use Override;

class SpatieMediaLibraryFileUpload extends FilamentSpatieMediaLibraryFileUpload
{
    /** @return list<string> */
    #[Override]
    public function getOriginalFilePaths(): array
    {
        return ResolveAdminUploadOriginalPathsAction::run($this);
    }
}
