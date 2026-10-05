<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Cache\Fixtures;

use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Http\Request;

final class CacheOriginContexts
{
    /** @return array<string, array{array<string, int|string>|string|null, bool}> */
    public static function standard(): array
    {
        $base = ['HTTP_HOST' => 'cache.example.test', 'SERVER_PORT' => '80', 'REMOTE_ADDR' => '10.0.0.1'];
        $forwarded = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'cache.example.test'];

        return [
            'direct HTTP with Host' => [$base, false],
            'direct HTTPS with Host' => [array_replace($base, ['HTTPS' => 'on', 'SERVER_PORT' => '443']), false],
            'trusted proto and host without port' => [$base + $forwarded, true],
            'trusted forwarded port 443' => [$base + $forwarded + ['HTTP_X_FORWARDED_PORT' => '443'], true],
            'trusted proto only over unix socket' => [$base + ['HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'unix socket HTTPS backend 80' => [$base + ['HTTPS' => 'on'], false],
            'untrusted proxy bare Host' => [$base + $forwarded, false],
            'untrusted proxy explicit Host 443' => [array_replace($base + $forwarded, ['HTTP_HOST' => 'cache.example.test:443']), false],
            'trusted proxy explicit Host 443' => [array_replace($base + $forwarded, ['HTTP_HOST' => 'cache.example.test:443']), true],
            'console APP_URL only' => ['console', false],
            'queue without request APP_URL only' => [null, false],
            'site domain HTTPS without port' => ['https://site.example.test/', false],
            'site domain HTTP without port' => ['http://site.example.test/', false],
            'explicit HTTP 80' => ['http://cache.example.test:80/', false],
            'explicit HTTPS 443' => ['https://cache.example.test:443/', false],
            'HTTP perceived on 443' => ['http://cache.example.test:443/', false],
            'HTTPS perceived on 80' => ['https://cache.example.test:80/', false],
            'hostless HTTP string 80' => [['SERVER_PORT' => '80'], false],
            'hostless HTTPS string 443' => [['HTTPS' => 'on', 'SERVER_PORT' => '443'], false],
            'hostless HTTPS string backend 80' => [['HTTPS' => 'on', 'SERVER_PORT' => '80'], false],
            'hostless HTTP integer 80' => [['SERVER_PORT' => 80], false],
            'hostless HTTPS integer 443' => [['HTTPS' => 'on', 'SERVER_PORT' => 443], false],
            'hostless HTTPS integer backend 80' => [['HTTPS' => 'on', 'SERVER_PORT' => 80], false],
            'hostless trusted HTTPS string backend 80' => [['SERVER_PORT' => '80', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'hostless absent port' => [[], false],
        ];
    }

    /** @return array<string, array{array<string, int|string>|string|null, bool}> */
    public static function nonStandard(): array
    {
        return [
            'direct HTTPS 8443' => ['https://cache.example.test:8443/', false],
            'hostless string 8443' => [['HTTPS' => 'on', 'SERVER_PORT' => '8443'], false],
            'hostless integer 8443' => [['HTTPS' => 'on', 'SERVER_PORT' => 8443], false],
            'trusted forwarded 8443' => [[
                'HTTP_HOST' => 'cache.example.test:9000', 'SERVER_PORT' => '9000', 'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'cache.example.test', 'HTTP_X_FORWARDED_PORT' => '8443',
            ], true],
            'untrusted forwarded port ignored' => [[
                'HTTP_HOST' => 'cache.example.test:8443', 'HTTPS' => 'on', 'SERVER_PORT' => '8443', 'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_PORT' => '9000',
            ], false],
            'console APP_URL 8443 only' => ['console', false],
            'queue without request APP_URL 8443 only' => [null, false],
        ];
    }

    /** @param array<string, int|string>|string|null $context */
    public static function bind(array|string|null $context, bool $trusted = false): void
    {
        Request::setTrustedProxies($trusted ? ['10.0.0.1'] : [], Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT);

        if ($context === 'console') {
            (new SetRequestForConsole)->bootstrap(app());
        } elseif ($context === null) {
            app()->offsetUnset('request');
        } else {
            $request = is_string($context) ? Request::create($context) : new Request(server: $context + [
                'SERVER_NAME' => 'cache.example.test', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/',
            ]);
            app()->instance('request', $request);
        }
    }
}
