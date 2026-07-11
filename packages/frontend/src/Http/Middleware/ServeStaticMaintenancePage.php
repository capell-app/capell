<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Core\Support\Security\LockdownStore;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Override;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ServeStaticMaintenancePage extends PreventRequestsDuringMaintenance
{
    #[Override]
    public function handle($request, Closure $next): mixed
    {
        $lockdownActive = resolve(LockdownStore::class)->active();

        if (! $lockdownActive && $this->inExceptArray($request)) {
            return $next($request);
        }

        $maintenanceData = null;
        $laravelMaintenanceActive = $this->app->maintenanceMode()->active();
        $manifest = resolve(MaintenanceManifestStore::class)->read();

        if (! $lockdownActive && ! $laravelMaintenanceActive && ! $this->manifestHasActiveMaintenance($manifest)) {
            return $next($request);
        }

        if (! $lockdownActive && ! $this->customMaintenancePagesEnabled()) {
            return $laravelMaintenanceActive ? parent::handle($request, $next) : $next($request);
        }

        if ($laravelMaintenanceActive) {
            $maintenanceData = $this->app->maintenanceMode()->data();

            if (! $lockdownActive) {
                if (isset($maintenanceData['secret']) && $request->path() === $maintenanceData['secret']) {
                    return $this->bypassResponse($maintenanceData['secret']);
                }

                if ($this->hasValidBypassCookie($request, $maintenanceData)) {
                    return $next($request);
                }

                if (isset($maintenanceData['redirect'])) {
                    $path = $maintenanceData['redirect'] === '/'
                        ? $maintenanceData['redirect']
                        : trim((string) $maintenanceData['redirect'], '/');

                    if ($request->path() !== $path) {
                        return redirect($path);
                    }
                }
            }
        }

        $entry = $this->matchingEntry($request, $manifest);

        if ($entry !== null && ($lockdownActive || $laravelMaintenanceActive || $entry['active'] === true)) {
            $response = $this->responseForEntry($entry, $maintenanceData ?? []);

            if ($response instanceof Response) {
                return $response;
            }
        }

        if ($lockdownActive) {
            return $this->lockdownFallbackResponse();
        }

        if ($laravelMaintenanceActive) {
            return $this->maintenanceFallbackResponse($maintenanceData);
        }

        return $next($request);
    }

    /** @param array<string, mixed> $manifest */
    private function matchingEntry(Request $request, array $manifest): ?array
    {
        $host = strtolower($request->getHost());
        $scheme = $request->getScheme();
        $path = $this->normalizePath($request->getPathInfo());
        $globalActive = ($manifest['global_active'] ?? false) === true;
        $matches = [];

        foreach (($manifest['sites'] ?? []) as $site) {
            if (! is_array($site)) {
                continue;
            }

            $siteActive = ($site['active'] ?? false) === true;

            foreach (($site['domains'] ?? []) as $domain) {
                if (! is_array($domain)) {
                    continue;
                }

                $domainPath = $this->normalizePath($domain['path'] ?? '/');
                if (($domain['scheme'] ?? null) !== $scheme) {
                    continue;
                }

                if (strtolower((string) ($domain['domain'] ?? '')) !== $host) {
                    continue;
                }

                if ($domainPath !== '/' && $path !== $domainPath && ! str_starts_with($path, rtrim($domainPath, '/') . '/')) {
                    continue;
                }

                $matches[] = [
                    ...$domain,
                    'active' => $globalActive || $siteActive,
                    'match_length' => strlen($domainPath),
                ];
            }
        }

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            fn (array $first, array $second): int => $second['match_length'] <=> $first['match_length'],
        );

        return $matches[0];
    }

    /** @param array<string, mixed> $maintenanceData */
    private function responseForEntry(array $entry, array $maintenanceData): ?Response
    {
        if (! app()->bound(StaticMaintenancePageStore::class)) {
            return null;
        }

        $file = $entry['file'] ?? null;

        if (! is_string($file) || $file === '') {
            return null;
        }

        $store = resolve(StaticMaintenancePageStore::class);
        $path = $store->path($file);

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return response(
            $contents !== false ? $contents : '',
            $maintenanceData['status'] ?? 503,
            [
                ...$this->getHeaders($maintenanceData),
                'Content-Type' => 'text/html; charset=UTF-8',
            ],
        );
    }

    /** @param array<string, mixed> $maintenanceData */
    private function maintenanceFallbackResponse(array $maintenanceData): Response
    {
        if (isset($maintenanceData['template'])) {
            return response(
                $maintenanceData['template'],
                $maintenanceData['status'] ?? 503,
                $this->getHeaders($maintenanceData),
            );
        }

        throw new HttpException(
            $maintenanceData['status'] ?? 503,
            'Service Unavailable',
            null,
            $this->getHeaders($maintenanceData),
        );
    }

    private function lockdownFallbackResponse(): Response
    {
        return response(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Service unavailable</title></head><body><main><h1>Service unavailable</h1><p>This site is temporarily unavailable.</p></main></body></html>',
            503,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /** @param array<string, mixed> $manifest */
    private function manifestHasActiveMaintenance(array $manifest): bool
    {
        if (($manifest['global_active'] ?? false) === true) {
            return true;
        }

        foreach (($manifest['sites'] ?? []) as $site) {
            if (is_array($site) && ($site['active'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(mixed $path): string
    {
        if (! is_string($path) || $path === '') {
            return '/';
        }

        return '/' . trim($path, '/');
    }

    private function customMaintenancePagesEnabled(): bool
    {
        try {
            return resolve(FrontendSettingsReaderInterface::class)->settings()->custom_maintenance_page_enabled;
        } catch (Throwable) {
            return true;
        }
    }
}
