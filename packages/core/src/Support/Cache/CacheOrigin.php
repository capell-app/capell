<?php

declare(strict_types=1);

namespace Capell\Core\Support\Cache;

use Illuminate\Http\Request;

final class CacheOrigin
{
    public static function discriminator(): string
    {
        $request = app()->bound('request') ? resolve('request') : null;
        $request = $request instanceof Request ? $request : Request::create((string) config('app.url'));

        $effectivePort = $request->getPort();
        $port = (int) $effectivePort;

        // Both standard ports retain legacy keys, even when the perceived scheme
        // differs behind a proxy or SERVER_PORT is a string or absent.
        if ($effectivePort === null || $port === 80 || $port === 443) {
            return '';
        }

        return ':' . $request->getScheme() . ':' . $port;
    }
}
