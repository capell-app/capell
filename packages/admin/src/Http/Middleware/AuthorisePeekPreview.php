<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Middleware;

use Capell\Admin\Support\Security\PeekPreviewGrants;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens Filament Peek's internal preview only for the signed-in user its token was granted to, while they can
 * still use the panel that rendered it. Anyone else gets the same not-found answer as a missing preview, and
 * the preview is never cached or sent on as a referrer.
 */
final readonly class AuthorisePeekPreview
{
    public function __construct(private PeekPreviewGrants $grants) {}

    /**
     * The preview route's middleware with the session and this grant check always present, whatever the
     * host configured.
     *
     * @return list<mixed>
     */
    public static function around(mixed $configured): array
    {
        $middleware = is_string($configured) ? [$configured] : (is_array($configured) ? array_values($configured) : []);

        if (! in_array('web', $middleware, true)) {
            array_unshift($middleware, 'web');
        }

        if (! in_array(self::class, $middleware, true)) {
            $middleware[] = self::class;
        }

        return $middleware;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');

        abort_unless(is_string($token) && $this->grants->allows($token), 404);

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
