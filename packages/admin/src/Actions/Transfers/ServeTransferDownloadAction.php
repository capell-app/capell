<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Transfers;

use Capell\Admin\Enums\TransferDownloadEnum;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\Transfers\TransferOrigin;
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
 * global route sends the visitor on to that panel's own copy of the route, on the host that served
 * the record, which runs the panel's whole stack and package security. A panel's copy serves only
 * the records that panel started, and a record with no provable origin is refused.
 */
final class ServeTransferDownloadAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, TransferDownloadEnum $download, string $key): Response
    {
        // Only a record started in a panel Capell still extends can be tied to a security stack. Any other,
        // including one started before origins were recorded, is refused with the way to recover.
        $origin = resolve(TransferOrigins::class)->originFor($download, $key);
        $panel = $origin instanceof TransferOrigin ? resolve(InstalledPanelRuntime::class)->capellPanel($origin->panel) : null;
        abort_unless($origin instanceof TransferOrigin && $panel instanceof Panel, 403, __('capell-admin::message.transfer_origin_unknown'));

        $route = $request->route();
        if (! $route instanceof Route || $route->getName() !== $download->globalRouteName()) {
            // A panel's own copy of the download serves only the records that panel started.
            $serving = Filament::getCurrentPanel();
            abort_unless($serving instanceof Panel && $serving->getId() === $panel->getId(), 404);

            return $this->serveWithFilament($request, $download, $key);
        }

        return redirect()->away($this->panelUrl($request, $download, $key, $panel, $origin->host));
    }

    /** The absolute URL of the panel's own copy of the download, on the host that served the record. */
    private function panelUrl(Request $request, TransferDownloadEnum $download, string $key, Panel $panel, string $host): string
    {
        $hostname = (string) parse_url('//' . $host, PHP_URL_HOST);
        $domains = array_values($panel->getDomains());
        abort_unless($domains === [] || in_array($hostname, $domains, true), 403, __('capell-admin::message.transfer_origin_unknown'));
        // Panels on several domains name each domain's routes.
        $name = 'filament.' . $panel->getId() . '.' . (count($domains) > 1 ? $hostname . '.' : '') . $download->panelRouteName();
        $parameters = [...Arr::except($request->query(), ['signature', 'expires']), $download->parameter() => $key];

        // Filament checks the signature of the relative URL, so the path is signed and the recorded host prefixed.
        // Only a URL Filament signed is signed again for the panel, carrying the same expiry.
        if ($request->hasValidSignature(absolute: false)) {
            $expires = $request->query('expires');
            $path = URL::signedRoute($name, $parameters, is_numeric($expires) ? Date::createFromTimestamp((int) $expires) : null, absolute: false);
        } else {
            $path = URL::route($name, $parameters, absolute: false);
        }

        return $request->getScheme() . '://' . $host . $path;
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
