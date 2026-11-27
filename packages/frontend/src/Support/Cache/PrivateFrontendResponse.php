<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a frontend request as rendering per-user output that no HTML cache may store.
 */
final class PrivateFrontendResponse
{
    public const string ATTRIBUTE = 'capell.frontend.private_response';

    public static function mark(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, true);
    }

    public static function isMarked(Request $request, ?Response $response = null): bool
    {
        if ($request->attributes->get(self::ATTRIBUTE) === true) {
            return true;
        }

        return $response instanceof Response && $response->headers->hasCacheControlDirective('no-store');
    }

    public static function applyHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary('Cookie', false);

        return $response;
    }
}
