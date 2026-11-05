<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * The route group through which Capell's admin code registers panel routes outside Filament's own panel
 * groups. It records, on every route it registers, the panel that serves it; the record is read as the
 * route is added to the router, where it decides the route's {@see PanelOwnership}.
 */
final class AdminRouteProvenance
{
    /** The route action attribute that records the declaration. */
    public const string ACTION = 'capell_admin';

    private const string PANEL = 'panel:';

    private const string GUESTS = 'guests';

    /**
     * Register routes the given panel serves, held to its whole stack on its domains. Routes serving guests
     * (credentialed by a URL signature, say) skip the panel's sign-in but keep its package middleware.
     */
    public static function panel(string $panel, Closure $routes, bool $guests = false): void
    {
        RouteFacade::group([self::ACTION => $guests ? [self::PANEL . $panel, self::GUESTS] : self::PANEL . $panel], $routes);
    }

    /**
     * The panels the route's groups declared, and whether a group declared that it serves guests.
     *
     * @return array{panels: list<string>, guests: bool}
     */
    public static function declared(Route $route): array
    {
        // Nested groups merge their attributes recursively, so nested declarations arrive as a list.
        $declared = array_filter((array) $route->getAction(self::ACTION), is_string(...));
        $panels = [];
        foreach ($declared as $declaration) {
            if (str_starts_with($declaration, self::PANEL)) {
                $panels[] = substr($declaration, strlen(self::PANEL));
            }
        }

        return ['panels' => array_values(array_unique($panels)), 'guests' => in_array(self::GUESTS, $declared, true)];
    }
}
