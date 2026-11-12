<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Closure;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The one way Capell code should open an HTTP connection to an address that came
 * from configuration, content or a user.
 *
 * `OutboundHttp::guarded()` returns an ordinary PendingRequest that, for the request
 * and for every redirect hop it is allowed to follow:
 *
 * - refuses schemes and ports the policy does not allow;
 * - resolves the host once and refuses it unless every address it resolves to is
 *   public under PublicAddressPolicy (IP literals are checked directly);
 * - pins the connection to the checked address, so a second DNS answer (DNS
 *   rebinding) cannot send it somewhere else;
 * - ignores proxy environment variables, which would otherwise resolve the host
 *   on the proxy and bypass the check;
 * - stops the transfer once the body exceeds the policy's byte limit;
 * - applies connect and total timeouts, and follows no redirects unless the
 *   policy allows them.
 *
 * It always uses the cURL handler, because pinning and the byte limit are cURL
 * options; PHP's stream handler (which Guzzle picks for `stream => true`) would
 * silently ignore both. Compose it with OutboundHttpRetry as with any other
 * PendingRequest.
 */
final class OutboundHttp
{
    public static function guarded(?OutboundHttpPolicy $policy = null): PendingRequest
    {
        $policy ??= new OutboundHttpPolicy;

        $request = Http::setHandler(new CurlHandler)
            ->connectTimeout($policy->connectTimeoutSeconds)
            ->timeout($policy->timeoutSeconds)
            ->withMiddleware(self::middleware($policy));

        if ($policy->maxRedirects === 0) {
            return $request->withoutRedirecting();
        }

        return $request->withOptions(['allow_redirects' => [
            'max' => $policy->maxRedirects,
            'strict' => true,
            'referer' => false,
            'protocols' => $policy->schemes,
            'track_redirects' => false,
        ]]);
    }

    /**
     * @return Closure(callable): (Closure(RequestInterface, array<string, mixed>): PromiseInterface)
     */
    private static function middleware(OutboundHttpPolicy $policy): Closure
    {
        return static fn (callable $handler): Closure => static function (RequestInterface $request, array $options) use ($handler, $policy): PromiseInterface {
            $options['curl'] = array_replace(
                is_array($options['curl'] ?? null) ? $options['curl'] : [],
                self::connectionOptions($request, $policy),
            );

            /** @var PromiseInterface $promise */
            $promise = $handler($request, $options);

            return $promise->then(static function (ResponseInterface $response) use ($request, $policy): ResponseInterface {
                $size = $response->getBody()->getSize();

                if ($size !== null && $size > $policy->maxBytes) {
                    throw OutboundRequestRefusedException::forHost(self::host($request), 'the response exceeds the download limit');
                }

                return $response;
            });
        };
    }

    /**
     * @return array<int, mixed>
     */
    private static function connectionOptions(RequestInterface $request, OutboundHttpPolicy $policy): array
    {
        $uri = $request->getUri();
        $host = self::host($request);
        $scheme = strtolower($uri->getScheme());

        if ($host === '' || ! $policy->allowsScheme($scheme)) {
            throw OutboundRequestRefusedException::forHost($host, 'the scheme is not allowed');
        }

        $port = $uri->getPort() ?? ($scheme === 'http' ? 80 : 443);

        if (! $policy->allowsPort($port)) {
            throw OutboundRequestRefusedException::forHost($host, 'the port is not allowed');
        }

        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $isLiteral ? [$host] : resolve(ResolvesHostAddresses::class)->resolve($host);

        if ($addresses === []) {
            throw OutboundRequestRefusedException::forHost($host, 'the host could not be resolved');
        }

        if (! resolve(PublicAddressPolicy::class)->allPublic($addresses)) {
            throw OutboundRequestRefusedException::forHost($host, 'the host resolves to a non-public address');
        }

        $maxBytes = $policy->maxBytes;
        $options = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static fn (mixed $handle, int $downloadTotal, int $downloaded): int => $downloaded > $maxBytes ? 1 : 0,
        ];

        if (! $isLiteral) {
            $address = $addresses[0];
            $options[CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? '[' . $address . ']' : $address)];
        }

        return $options;
    }

    private static function host(RequestInterface $request): string
    {
        return strtolower(trim($request->getUri()->getHost(), '[]'));
    }
}
