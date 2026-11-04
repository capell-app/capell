<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers\Transfers;

use Capell\Admin\Actions\Transfers\ServeTransferDownloadAction;
use Capell\Admin\Enums\TransferDownloadEnum;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Filament's global import failed-rows download, served through the panel the import was started in. */
final class DownloadImportFailuresInOriginatingPanelController
{
    public function __invoke(Request $request, string $import): Response
    {
        return ServeTransferDownloadAction::run($request, TransferDownloadEnum::ImportFailures, $import);
    }
}
