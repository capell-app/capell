<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Filament\Panel;
use Illuminate\Routing\Router;
use ReflectionProperty;
use RuntimeException;
use WeakMap;

/** Tracks application wiring per panel, independently of transient plugin instances. */
final class InstalledPanelRuntime
{
    /** @var WeakMap<Panel, array<int, bool>> */
    private WeakMap $applied;

    /** @var WeakMap<Panel, array{middleware: array<string>, auth: array<string>}> */
    private WeakMap $routeMiddleware;

    public function __construct(private readonly Router $router)
    {
        $this->applied = new WeakMap;
        $this->routeMiddleware = new WeakMap;
    }

    public function extend(Panel $panel): void
    {
        $hasId = new ReflectionProperty($panel, 'id')->isInitialized($panel);
        $baseline = $this->routeMiddleware[$panel] ?? [
            'middleware' => $hasId ? $panel->getMiddleware() : [],
            'auth' => $panel->getAuthMiddleware(),
        ];
        if ($hasId) {
            // Retain the last synchronised route state if an extender fails halfway.
            $this->routeMiddleware[$panel] = $baseline;
        }

        $middleware = $baseline['middleware'];
        $authMiddleware = $baseline['auth'];
        $applied = $this->applied[$panel] ?? [];
        $index = 0;

        foreach (app()->tagged(AdminPanelExtender::TAG) as $extender) {
            $entry = $index++;
            if (! $extender instanceof AdminPanelExtender) {
                continue;
            }

            if (isset($applied[$entry])) {
                continue;
            }

            $extender->extend($panel);
            $applied[$entry] = true;
            $this->applied[$panel] = $applied;
        }

        if (! $hasId) {
            return;
        }

        $addedMiddleware = array_values(array_diff($panel->getMiddleware(), $middleware));
        $addedAuthMiddleware = array_values(array_diff($panel->getAuthMiddleware(), $authMiddleware));
        if ($addedMiddleware === [] && $addedAuthMiddleware === []) {
            return;
        }

        // Routes copy panel middleware at construction. Change the named route
        // instance too, including the compiled collection's cached instance.
        $routes = $this->router->getRoutes();
        foreach ($routes->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }

            if (! str_starts_with((string) $name, 'filament.' . $panel->getId() . '.')) {
                continue;
            }

            throw_if($addedAuthMiddleware !== [] && $authMiddleware === [], RuntimeException::class, 'Cannot identify authenticated panel routes for newly installed middleware; rebuild the application before serving the panel.');

            $route = $routes->getByName($name) ?? $route;
            $authenticated = $authMiddleware !== [] && array_diff($authMiddleware, $route->middleware()) === [];
            $route->middleware($addedMiddleware);
            if ($authenticated) {
                $route->middleware($addedAuthMiddleware);
            }

            $route->computedMiddleware = null;
        }

        $this->routeMiddleware[$panel] = ['middleware' => $panel->getMiddleware(), 'auth' => $panel->getAuthMiddleware()];
    }
}
