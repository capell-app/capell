<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers;

use Capell\Frontend\Support\Privacy\PrivacyRuntimeScript;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the default consent runtime as a same-origin script, outside the session
 * middleware, so the response carries no cookie and a CDN can cache it. Only the URL
 * naming the current content hash is cached, for a year; any other version is sent to
 * the current URL uncached, so an old page or an arbitrary `v` cannot pin the wrong script.
 */
final readonly class ShowPrivacyRuntimeScriptController
{
    public function __construct(private PrivacyRuntimeScript $script) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->query('v') !== $this->script->version()) {
            return redirect($this->script->url(), Response::HTTP_FOUND)
                ->header('Cache-Control', 'no-store');
        }

        return response($this->script->contents(), Response::HTTP_OK, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
