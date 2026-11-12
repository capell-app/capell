<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * @param  array<string, list<string>>  $answers
 */
function fakeHostAnswers(array $answers): void
{
    app()->instance(ResolvesHostAddresses::class, new readonly class($answers) implements ResolvesHostAddresses
    {
        /** @param array<string, list<string>> $answers */
        public function __construct(private array $answers) {}

        public function resolve(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    });
}

/**
 * Captures the transport options the guard hands to the next handler, so the
 * pinning and limits can be asserted without opening a socket.
 *
 * @param  array<int, mixed>|null  $captured
 */
function captureCurlOptions(?array &$captured): Closure
{
    return static function (callable $handler) use (&$captured): Closure {
        return static function (RequestInterface $request, array $options) use ($handler, &$captured) {
            $captured = $options['curl'] ?? null;

            return $handler($request, $options);
        };
    };
}

it('refuses a host that resolves to a private address before sending anything', function (): void {
    Http::fake();
    fakeHostAnswers(['intranet.example.test' => ['10.0.0.5']]);

    expect(fn () => OutboundHttp::guarded()->get('https://intranet.example.test/'))
        ->toThrow(OutboundRequestRefusedException::class, 'non-public address');

    Http::assertNothingSent();
});

it('refuses a host when any one of its addresses is not public', function (): void {
    Http::fake();
    fakeHostAnswers(['split.example.test' => ['93.184.216.34', '169.254.169.254']]);

    expect(fn () => OutboundHttp::guarded()->get('https://split.example.test/'))
        ->toThrow(OutboundRequestRefusedException::class, 'non-public address');

    Http::assertNothingSent();
});

it('refuses a host that does not resolve', function (): void {
    Http::fake();
    fakeHostAnswers([]);

    expect(fn () => OutboundHttp::guarded()->get('https://missing.example.test/'))
        ->toThrow(OutboundRequestRefusedException::class, 'could not be resolved');

    Http::assertNothingSent();
});

it('refuses private and transition IP literals', function (string $url): void {
    Http::fake();

    expect(fn () => OutboundHttp::guarded()->get($url))->toThrow(OutboundRequestRefusedException::class);

    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/',
    'IPv6 loopback' => 'http://[::1]/',
    'IPv4-mapped loopback' => 'http://[::ffff:127.0.0.1]/',
    'NAT64 private' => 'http://[64:ff9b::a00:1]/',
    'carrier-grade NAT' => 'http://100.64.0.1/',
]);

it('refuses ports and schemes the policy does not allow', function (string $url, OutboundHttpPolicy $policy): void {
    Http::fake();
    fakeHostAnswers(['public.example.test' => ['93.184.216.34']]);

    expect(fn () => OutboundHttp::guarded($policy)->get($url))->toThrow(OutboundRequestRefusedException::class, 'is not allowed');

    Http::assertNothingSent();
})->with([
    'redis port' => ['https://public.example.test:6379/', new OutboundHttpPolicy],
    'plain http on an https-only policy' => ['http://public.example.test/', new OutboundHttpPolicy(schemes: ['https'])],
]);

it('pins the connection to the checked address and ignores proxies', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    fakeHostAnswers(['public.example.test' => ['2606:2800:220:1:248:1893:25c8:1946']]);
    $captured = null;

    OutboundHttp::guarded(new OutboundHttpPolicy(ports: [8443]))
        ->withMiddleware(captureCurlOptions($captured))
        ->get('https://public.example.test:8443/feed');

    expect($captured)->toBeArray();
    $options = is_array($captured) ? $captured : [];
    $abort = $options[CURLOPT_XFERINFOFUNCTION] ?? null;

    expect($options[CURLOPT_RESOLVE] ?? null)->toBe(['public.example.test:8443:[2606:2800:220:1:248:1893:25c8:1946]'])
        ->and($options[CURLOPT_PROXY] ?? null)->toBe('')
        ->and($options[CURLOPT_NOPROXY] ?? null)->toBe('*')
        ->and($options[CURLOPT_MAXFILESIZE] ?? null)->toBe(OutboundHttpPolicy::DEFAULT_MAX_BYTES)
        ->and($options[CURLOPT_NOPROGRESS] ?? null)->toBeFalse()
        ->and($abort)->toBeCallable();
    assert(is_callable($abort));
    expect($abort(null, 0, OutboundHttpPolicy::DEFAULT_MAX_BYTES + 1, 0, 0))->toBe(1)
        ->and($abort(null, 0, OutboundHttpPolicy::DEFAULT_MAX_BYTES, 0, 0))->toBe(0);
});

it('does not follow redirects unless the policy allows them', function (): void {
    Http::fake(['https://public.example.test/*' => Http::response('', 302, ['Location' => 'https://public.example.test/next'])]);
    fakeHostAnswers(['public.example.test' => ['93.184.216.34']]);

    $response = OutboundHttp::guarded()->get('https://public.example.test/start');

    expect($response->status())->toBe(302);
    Http::assertSentCount(1);
});

it('re-checks every redirect hop and refuses one that leads to a private host', function (): void {
    Http::fake([
        'https://public.example.test/*' => Http::response('', 302, ['Location' => 'http://metadata.example.test/latest']),
        'http://metadata.example.test/*' => Http::response('secret'),
    ]);
    fakeHostAnswers([
        'public.example.test' => ['93.184.216.34'],
        'metadata.example.test' => ['169.254.169.254'],
    ]);

    expect(fn () => OutboundHttp::guarded(new OutboundHttpPolicy(maxRedirects: 3))->get('https://public.example.test/start'))
        ->toThrow(OutboundRequestRefusedException::class, 'metadata.example.test');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://public.example.test/start');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'metadata.example.test'));
});

it('follows an allowed redirect to another public host', function (): void {
    Http::fake([
        'https://public.example.test/*' => Http::response('', 301, ['Location' => 'https://cdn.example.test/file']),
        'https://cdn.example.test/*' => Http::response('done'),
    ]);
    fakeHostAnswers([
        'public.example.test' => ['93.184.216.34'],
        'cdn.example.test' => ['93.184.216.35'],
    ]);

    $response = OutboundHttp::guarded(new OutboundHttpPolicy(maxRedirects: 1))->get('https://public.example.test/start');

    expect($response->body())->toBe('done');
});

it('refuses a response body larger than the download limit', function (): void {
    Http::fake(['https://public.example.test/*' => Http::response(str_repeat('a', 2048))]);
    fakeHostAnswers(['public.example.test' => ['93.184.216.34']]);

    expect(fn () => OutboundHttp::guarded(new OutboundHttpPolicy(maxBytes: 1024))->get('https://public.example.test/big'))
        ->toThrow(OutboundRequestRefusedException::class, 'download limit');
});

it('names only the host when it refuses, never the path or query', function (): void {
    Http::fake();
    fakeHostAnswers(['intranet.example.test' => ['10.0.0.5']]);

    try {
        OutboundHttp::guarded()->get('https://intranet.example.test/hook?token=secret-value');
        $this->fail('The request was not refused.');
    } catch (OutboundRequestRefusedException $outboundRequestRefusedException) {
        expect($outboundRequestRefusedException->getMessage())->toContain('intranet.example.test')
            ->not->toContain('secret-value')
            ->not->toContain('/hook');
    }
});

it('rejects policies that would loosen the guard beyond its limits', function (Closure $build): void {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'file scheme' => [fn (): OutboundHttpPolicy => new OutboundHttpPolicy(schemes: ['file'])],
    'no ports' => [fn (): OutboundHttpPolicy => new OutboundHttpPolicy(ports: [])],
    'too many redirects' => [fn (): OutboundHttpPolicy => new OutboundHttpPolicy(maxRedirects: 11)],
    'no download limit' => [fn (): OutboundHttpPolicy => new OutboundHttpPolicy(maxBytes: 0)],
]);
