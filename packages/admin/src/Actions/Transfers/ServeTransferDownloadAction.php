<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Transfers;

use Capell\Admin\Enums\TransferDownloadEnum;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\Transfers\TransferOrigins;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send a visitor of Filament's global download route on to the copy of that route inside the panel
 * the record was started in, so the download runs that panel's whole stack and package security.
 */
final class ServeTransferDownloadAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, TransferDownloadEnum $download, string $key): Response
    {
        $panel = resolve(InstalledPanelRuntime::class)->originatingPanel(resolve(TransferOrigins::class)->panelFor($download, $key));
        if (! $panel instanceof Panel) {
            // A record of a Filament panel Capell does not extend keeps Filament's own route and stack.
            return $this->serveWithFilament($request, $download, $key);
        }

        $name = $panel->generateRouteName($download->panelRouteName());
        $parameters = [...Arr::except($request->query(), ['signature', 'expires']), $download->parameter() => $key];
        // Only a URL Filament signed is signed again for the panel, carrying the same expiry.
        if (! $request->hasValidSignature(absolute: false)) {
            return redirect(URL::route($name, $parameters, absolute: false));
        }

        $expires = $request->query('expires');

        return redirect(URL::signedRoute($name, $parameters, is_numeric($expires) ? Date::createFromTimestamp((int) $expires) : null, absolute: false));
    }

    private function serveWithFilament(Request $request, TransferDownloadEnum $download, string $key): Response
    {
        $model = resolve($download->model());
        $record = $model instanceof Model ? $model->resolveRouteBinding($key) : null;
        abort_unless($record instanceof Model, 404);

        $response = resolve($download->filamentController())($request, $record);

        return $response instanceof Response ? $response : response($response);
    }
}
