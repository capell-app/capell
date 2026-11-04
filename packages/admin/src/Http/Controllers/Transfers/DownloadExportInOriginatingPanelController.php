<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers\Transfers;

use Capell\Admin\Actions\Transfers\ServeTransferDownloadAction;
use Capell\Admin\Enums\TransferDownloadEnum;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Filament's export download: the global route sends the visitor to the originating panel, whose copy serves only its own exports. */
final class DownloadExportInOriginatingPanelController
{
    public function __invoke(Request $request, string $export): Response
    {
        return ServeTransferDownloadAction::run($request, TransferDownloadEnum::Export, $export);
    }
}
