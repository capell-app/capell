<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class ETagMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldAddETag($response)) {
            return $response;
        }

        $etag = $this->generateETag($response);
        $response->headers->set('ETag', $etag);

        // If client sent If-None-Match and it matches, return 304 Not Modified
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => $response->headers->get('Cache-Control') ?? 'public',
                'Vary' => $response->headers->get('Vary') ?? 'Accept-Encoding',
            ]);
        }

        // Add Last-Modified header based on content hash
        if (! $response->headers->has('Last-Modified')) {
            $lastModified = gmdate('D, d M Y H:i:s T', Date::now()->getTimestamp());
            $response->headers->set('Last-Modified', $lastModified);
        }

        return $response;
    }

    private function shouldAddETag(Response $response): bool
    {
        if (! in_array($response->getStatusCode(), [200, 404], true)) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type');
        if ($contentType === null) {
            return false;
        }

        // Only add ETag to HTML and JSON responses
        return mb_strpos($contentType, 'text/html') !== false
            || mb_strpos($contentType, 'application/json') !== false;
    }

    private function generateETag(Response $response): string
    {
        // Generate a weak ETag from content hash
        // W/ prefix indicates this is a weak ETag (sufficient for this use case)
        $contentHash = hash('xxh128', (string) $response->getContent());

        return 'W/"' . substr($contentHash, 0, 16) . '"';
    }
}
