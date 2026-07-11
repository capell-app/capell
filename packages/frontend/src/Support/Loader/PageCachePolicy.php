<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PageCachePolicy
{
    public static function shouldCache(?Pageable $page): bool
    {
        if (! $page instanceof Pageable) {
            return false;
        }

        if (! $page instanceof Model || ! $page->relationLoaded('type')) {
            return false;
        }

        return $page->type?->cache_time !== CacheTime::Never;
    }

    public function eligible(Request $request, Response $response): bool
    {
        if ($request->method() !== 'GET') {
            return false;
        }

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return false;
        }

        if ($response->headers->get('X-Capell-Public-Html-Safety') !== null) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        return stripos((string) $contentType, 'text/html') !== false;
    }
}
