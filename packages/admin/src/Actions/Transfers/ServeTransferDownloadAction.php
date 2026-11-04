<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Transfers;

use Capell\Admin\Enums\TransferDownloadEnum;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\Transfers\TransferOrigins;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve Filament's export and import downloads through the panel each record was started in. The
 * global route sends the visitor on to that panel's own copy of the route, which runs the panel's
 * whole stack and package security, and a panel's copy serves only the records that panel started.
 */
final class ServeTransferDownloadAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, TransferDownloadEnum $download, string $key): Response
    {
        $origin = resolve(TransferOrigins::class)->originFor($download, $key);
        $panel = resolve(InstalledPanelRuntime::class)->originatingPanel($origin?->panel);
        $route = $request->route();
        if (! $route instanceof Route || $route->getName() !== $download->globalRouteName()) {
            $serving = Filament::getCurrentPanel();
            abort_unless($panel instanceof Panel && $serving instanceof Panel && $panel->getId() === $serving->getId(), 404);

            return $this->serveWithFilament($request, $download, $key);
        }

        if (! $panel instanceof Panel) {
            // A record of a Filament panel Capell does not extend keeps Filament's own route and stack.
            return $this->serveWithFilament($request, $download, $key);
        }

        return redirect()->away($this->panelUrl($request, $download, $key, $panel, $origin?->domain));
    }

    /** The absolute URL of the panel's own copy of the download, on the panel domain that served the record. */
    private function panelUrl(Request $request, TransferDownloadEnum $download, string $key, Panel $panel, ?string $recordedDomain): string
    {
        $domains = array_values($panel->getDomains());
        $domain = $domains === [] ? null : (in_array($recordedDomain, $domains, true) ? $recordedDomain : $domains[0]);
        // Panels on several domains name each domain's routes.
        $name = 'filament.' . $panel->getId() . '.' . (count($domains) > 1 ? $domain . '.' : '') . $download->panelRouteName();
        $parameters = [...Arr::except($request->query(), ['signature', 'expires']), $download->parameter() => $key];

        // Filament checks the signature of the relative URL, so the path is signed and the panel's host prefixed.
        // Only a URL Filament signed is signed again for the panel, carrying the same expiry.
        if ($request->hasValidSignature(absolute: false)) {
            $expires = $request->query('expires');
            $path = URL::signedRoute($name, $parameters, is_numeric($expires) ? Date::createFromTimestamp((int) $expires) : null, absolute: false);
        } else {
            $path = URL::route($name, $parameters, absolute: false);
        }

        if ($domain === null) {
            return $request->getSchemeAndHttpHost() . $path;
        }

        $port = (int) $request->getPort();

        return $request->getScheme() . '://' . $domain . (in_array($port, [0, $request->isSecure() ? 443 : 80], true) ? '' : ':' . $port) . $path;
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
