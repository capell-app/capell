<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Admin\Http\Controllers\Transfers\DownloadExportInOriginatingPanelController;
use Capell\Admin\Http\Controllers\Transfers\DownloadImportFailuresInOriginatingPanelController;
use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Http\Controllers\DownloadImportFailureCsv;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Filament's global download routes for the records its export and import actions create. Each
 * record is downloaded through the panel it was started in: the global route only sends the
 * visitor on to that panel's own copy of the route, which runs the panel's whole stack.
 */
enum TransferDownloadEnum: string
{
    case Export = 'export';
    case ImportFailures = 'import';

    public static function forModel(Model $model): ?self
    {
        return match (true) {
            $model instanceof Export => self::Export,
            $model instanceof Import => self::ImportFailures,
            default => null,
        };
    }

    /** Register each download inside a panel's authenticated routes; each serves only the records its panel started. */
    public static function registerPanelRoutes(): void
    {
        foreach (self::cases() as $download) {
            RouteFacade::get($download->panelUri(), $download->originController())->name($download->panelRouteName());
        }
    }

    /**
     * Point a route that runs Filament's download controller, as Filament's global download routes do,
     * at the controller that serves each record through its originating panel. Done as the route is
     * registered, so `route:cache` stores the new action and no later scan is needed.
     */
    public static function takeOverRegisteredRoute(Route $route): void
    {
        $controller = $route->getControllerClass();
        $download = array_find(self::cases(), static fn (self $download): bool => $download->filamentController() === $controller);
        if ($download instanceof self) {
            $route->uses($download->originController());
        }
    }

    public static function tryFromGlobalRouteName(string $name): ?self
    {
        return array_find(self::cases(), static fn (self $download): bool => $download->globalRouteName() === $name);
    }

    public function globalRouteName(): string
    {
        return match ($this) {
            self::Export => 'filament.exports.download',
            self::ImportFailures => 'filament.imports.failed-rows.download',
        };
    }

    /** The route name inside a panel, before the panel's own prefix. */
    public function panelRouteName(): string
    {
        return match ($this) {
            self::Export => 'capell.exports.download',
            self::ImportFailures => 'capell.imports.failed-rows.download',
        };
    }

    /** The route parameter carrying the record's key. */
    public function parameter(): string
    {
        return match ($this) {
            self::Export => 'export',
            self::ImportFailures => 'import',
        };
    }

    /** @return class-string */
    public function filamentController(): string
    {
        return match ($this) {
            self::Export => DownloadExport::class,
            self::ImportFailures => DownloadImportFailureCsv::class,
        };
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return match ($this) {
            self::Export => Export::class,
            self::ImportFailures => Import::class,
        };
    }

    /** @return class-string */
    private function originController(): string
    {
        return match ($this) {
            self::Export => DownloadExportInOriginatingPanelController::class,
            self::ImportFailures => DownloadImportFailuresInOriginatingPanelController::class,
        };
    }

    private function panelUri(): string
    {
        return match ($this) {
            self::Export => 'capell/exports/{export}/download',
            self::ImportFailures => 'capell/imports/{import}/failed-rows/download',
        };
    }
}
