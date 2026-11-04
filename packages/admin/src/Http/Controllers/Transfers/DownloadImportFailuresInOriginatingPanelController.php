<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers\Transfers;

use Capell\Admin\Actions\Transfers\ServeTransferDownloadAction;
use Capell\Admin\Enums\TransferDownloadEnum;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Filament's import failed-rows download: the global route sends the visitor to the originating panel, whose copy serves only its own imports. */
final class DownloadImportFailuresInOriginatingPanelController
{
    public function __invoke(Request $request, string $import): Response
    {
        return ServeTransferDownloadAction::run($request, TransferDownloadEnum::ImportFailures, $import);
    }
}
