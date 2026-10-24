<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Actions\Media\ResolveAdminUploadOriginalPathsAction;
use Filament\Forms\Components\FileUpload as FilamentFileUpload;
use Override;

class FileUpload extends FilamentFileUpload
{
    /** @return list<string> */
    #[Override]
    public function getOriginalFilePaths(): array
    {
        return ResolveAdminUploadOriginalPathsAction::run($this);
    }
}
