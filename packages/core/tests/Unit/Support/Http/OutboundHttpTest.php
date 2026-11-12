<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
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
 * @param  array<string, mixed>|null  $captured
 */
function captureRequestOptions(?array &$captured): Closure
{
    return static function (callable $handler) use (&$captured): Closure {
        return static function (RequestInterface $request, array $options) use ($handler, &$captured): PromiseInterface {
            $captured = $options;

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

it('pins the connection to the checked address, ignores proxies and limits the download', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    fakeHostAnswers(['public.example.test' => ['2606:2800:220:1:248:1893:25c8:1946']]);
    $captured = null;

    OutboundHttp::guarded(new OutboundHttpPolicy(ports: [8443], maxBytes: 1024))
        ->withMiddleware(captureRequestOptions($captured))
        ->get('https://public.example.test:8443/feed');

    $options = is_array($captured) ? $captured : [];
    $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];

    expect($curl[CURLOPT_RESOLVE] ?? null)->toBe(['public.example.test:8443:[2606:2800:220:1:248:1893:25c8:1946]'])
        ->and($options['proxy'] ?? null)->toBe('');

    $onHeaders = $options['on_headers'] ?? null;
    assert(is_callable($onHeaders));
    $onHeaders(new Psr7Response(200, ['Content-Length' => '1024']));
    expect(fn () => $onHeaders(new Psr7Response(200, ['Content-Length' => '1025'])))
        ->toThrow(OutboundRequestRefusedException::class, 'download limit');

    if (constant(ClientInterface::class . '::MAJOR_VERSION') < 8) {
        // Guzzle 7 cannot abort from its progress option, so the limit is cURL's own.
        $abort = $curl[CURLOPT_XFERINFOFUNCTION] ?? null;
        assert(is_callable($abort));
        expect($curl[CURLOPT_MAXFILESIZE] ?? null)->toBe(1024)
            ->and($curl[CURLOPT_NOPROGRESS] ?? null)->toBeFalse()
            ->and($abort(null, 0, 1025, 0, 0))->toBe(1)
            ->and($abort(null, 0, 1024, 0, 0))->toBe(0);

        return;
    }

    // Guzzle 8 rejects those raw cURL options and aborts through its progress option.
    $progress = $options['progress'] ?? null;
    assert(is_callable($progress));
    expect($curl)->toHaveKeys([CURLOPT_RESOLVE])->toHaveCount(1)
        ->and($progress(0, 1024, 0, 0))->toBeFalse()
        ->and(fn () => $progress(0, 1025, 0, 0))->toThrow(OutboundRequestRefusedException::class);
});

it('surfaces the guard refusal that Guzzle wrapped from a header or progress callback', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    fakeHostAnswers(['public.example.test' => ['93.184.216.34']]);

    $wrapped = static fn (callable $handler): Closure => static fn (RequestInterface $request, array $options): PromiseInterface => Create::rejectionFor(
        new RuntimeException('An error was encountered during the on_headers event', 0, OutboundRequestRefusedException::forHost('public.example.test', OutboundRequestRefusedException::REASON_SIZE, 'the response exceeds the download limit')),
    );

    expect(fn () => OutboundHttp::guarded()->withMiddleware($wrapped)->get('https://public.example.test/big'))
        ->toThrow(OutboundRequestRefusedException::class, 'download limit');
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

it('reports a transfer cURL stopped at the download limit as a size refusal', function (int $errno): void {
    Http::fake(['*' => Http::response('ok')]);
    fakeHostAnswers(['public.example.test' => ['93.184.216.34']]);

    $stoppedByCurl = static fn (callable $handler): Closure => static fn (RequestInterface $request, array $options): PromiseInterface => Create::rejectionFor(
        new ConnectException(sprintf('cURL error %d: transfer stopped', $errno), $request),
    );

    try {
        OutboundHttp::guarded()->withMiddleware($stoppedByCurl)->get('https://public.example.test/big');
        $this->fail('The oversize transfer was not refused.');
    } catch (OutboundRequestRefusedException $outboundRequestRefusedException) {
        expect($outboundRequestRefusedException->reason)->toBe(OutboundRequestRefusedException::REASON_SIZE)
            ->and($outboundRequestRefusedException->getPrevious())->toBeInstanceOf(ConnectException::class);
    }
})->with(['aborted by the transfer callback' => 42, 'declared length over the limit' => 63]);

it('leaves other transport failures as connection errors', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    fakeHostAnswers(['public.example.test' => ['93.184.216.34']]);

    $refused = static fn (callable $handler): Closure => static fn (RequestInterface $request, array $options): PromiseInterface => Create::rejectionFor(
        new ConnectException('cURL error 7: Failed to connect', $request),
    );

    expect(fn () => OutboundHttp::guarded()->withMiddleware($refused)->get('https://public.example.test/'))
        ->toThrow(ConnectionException::class);
});
