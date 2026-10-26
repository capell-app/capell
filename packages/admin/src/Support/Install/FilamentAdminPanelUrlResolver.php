<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Install;

use Capell\Core\Contracts\AdminPanelUrlResolver;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Route;
use Throwable;

final class FilamentAdminPanelUrlResolver implements AdminPanelUrlResolver
{
    public function resolve(?string $panelId = null): ?string
    {
        try {
            $panel = Filament::getPanel($panelId ?? (string) config('capell-admin.panel.id', 'admin'));
            $applicationUrl = (string) config('app.url');
            $urls = clone resolve(UrlGenerator::class);
            $urls->setRequest(Request::create($applicationUrl));
            $urls->forceRootUrl($applicationUrl);
            $urls->forceScheme((string) parse_url($applicationUrl, PHP_URL_SCHEME));

            // Use the registered panel, never the installer request's host or
            // current panel. Multi-domain panels use their first configured host.
            $domains = array_values($panel->getDomains());
            $domainPrefix = count($domains) > 1 ? $domains[0] . '.' : '';
            $homeRoute = 'filament.' . $panel->getId() . '.' . $domainPrefix . 'home';

            if (Route::has($homeRoute) && ! $panel->hasTenancy()) {
                return $urls->route($homeRoute);
            }

            if ($domains !== []) {
                $urls->forceRootUrl(parse_url($applicationUrl, PHP_URL_SCHEME) . '://' . $domains[0]);
            }

            return $urls->to($panel->getPath());
        } catch (Throwable) {
            return null;
        }
    }
}
