<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The one way Capell code should open an HTTP connection to an address that came
 * from configuration, content or a user.
 *
 * `OutboundHttp::guarded()` returns an ordinary PendingRequest that, for the request
 * and for every redirect hop it is allowed to follow:
 *
 * - refuses schemes and ports the policy does not allow, and URLs that carry
 *   credentials (a user name or password before the host), which would be sent
 *   to the target and written wherever the URL is logged;
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
 * It always uses the cURL handler, because pinning and the byte limit rely on
 * cURL; PHP's stream handler (which Guzzle picks for `stream => true`) would
 * silently ignore both. Compose it with OutboundHttpRetry as with any other
 * PendingRequest.
 */
final class OutboundHttp
{
    /** CURLE_ABORTED_BY_CALLBACK and CURLE_FILESIZE_EXCEEDED. */
    private const array DOWNLOAD_LIMIT_CURL_ERRORS = [42, 63];

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
            $options = self::requestOptions($request, $policy, $options);

            /** @var PromiseInterface $promise */
            $promise = $handler($request, $options);

            return $promise->then(
                static function (ResponseInterface $response) use ($request, $policy): ResponseInterface {
                    $size = $response->getBody()->getSize();

                    if ($size !== null && $size > $policy->maxBytes) {
                        throw self::tooLarge($request);
                    }

                    return $response;
                },
                static function (mixed $reason) use ($request): PromiseInterface {
                    if (! $reason instanceof Throwable) {
                        return Create::rejectionFor($reason);
                    }

                    // Guzzle wraps an exception thrown from on_headers or a
                    // progress callback; surface the guard's own refusal.
                    for ($current = $reason; $current instanceof Throwable; $current = $current->getPrevious()) {
                        throw_if($current instanceof OutboundRequestRefusedException, $current);
                    }

                    // cURL stops an oversize transfer with "maximum file size
                    // exceeded" (declared length) or "aborted by callback" (the
                    // transfer callback); both are the download limit, not a
                    // network failure.
                    if (in_array(self::curlErrorNumber($reason), self::DOWNLOAD_LIMIT_CURL_ERRORS, true)) {
                        throw self::tooLarge($request, $reason);
                    }

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Guards the connection for one request or redirect hop. Guzzle 8 refuses
     * most raw cURL options, so only CURLOPT_RESOLVE travels as one; the proxy,
     * header and progress limits use request options both majors honour.
     * Guzzle 7 cannot abort a transfer from its progress option (PHP ignores an
     * exception thrown in a cURL callback), so on 7 the limit is set as cURL
     * options instead.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function requestOptions(RequestInterface $request, OutboundHttpPolicy $policy, array $options): array
    {
        $uri = $request->getUri();
        $host = self::host($request);
        $scheme = strtolower($uri->getScheme());

        if ($host === '' || ! $policy->allowsScheme($scheme)) {
            throw OutboundRequestRefusedException::forHost($host, OutboundRequestRefusedException::REASON_SCHEME, 'the scheme is not allowed');
        }

        if ($uri->getUserInfo() !== '') {
            throw OutboundRequestRefusedException::forHost($host, OutboundRequestRefusedException::REASON_CREDENTIALS, 'the URL must not contain credentials');
        }

        $port = $uri->getPort() ?? ($scheme === 'http' ? 80 : 443);

        if (! $policy->allowsPort($port)) {
            throw OutboundRequestRefusedException::forHost($host, OutboundRequestRefusedException::REASON_PORT, 'the port is not allowed');
        }

        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $isLiteral ? [$host] : resolve(ResolvesHostAddresses::class)->resolve($host);

        if ($addresses === []) {
            throw OutboundRequestRefusedException::forHost($host, OutboundRequestRefusedException::REASON_UNRESOLVED, 'the host could not be resolved');
        }

        if (! resolve(PublicAddressPolicy::class)->allPublic($addresses)) {
            throw OutboundRequestRefusedException::forHost($host, OutboundRequestRefusedException::REASON_ADDRESS, 'the host resolves to a non-public address');
        }

        $maxBytes = $policy->maxBytes;
        $curl = is_array($options['curl'] ?? null) ? $options['curl'] : [];
        unset($curl[CURLOPT_RESOLVE]);

        if (! $isLiteral) {
            $address = $addresses[0];
            $curl[CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? '[' . $address . ']' : $address)];
        }

        if (self::guzzleMajorVersion() < 8) {
            $curl[CURLOPT_MAXFILESIZE] = $maxBytes;
            $curl[CURLOPT_NOPROGRESS] = false;
            $curl[CURLOPT_XFERINFOFUNCTION] = static fn (mixed $handle, int $downloadTotal, int $downloaded): int => $downloaded > $maxBytes ? 1 : 0;
            unset($options['progress']);
        } else {
            $options['progress'] = static function (int $downloadTotal, int $downloaded) use ($request, $maxBytes): bool {
                if ($downloaded > $maxBytes) {
                    throw self::tooLarge($request);
                }

                return false;
            };
        }

        $options['curl'] = $curl;
        // An empty proxy disables proxies, including HTTP(S)_PROXY from the
        // environment, which would resolve the host on the proxy instead.
        $options['proxy'] = '';
        $options['on_headers'] = static function (ResponseInterface $response) use ($request, $maxBytes): void {
            $length = $response->getHeaderLine('Content-Length');

            if (ctype_digit($length) && (int) $length > $maxBytes) {
                throw self::tooLarge($request);
            }
        };

        return $options;
    }

    private static function tooLarge(RequestInterface $request, ?Throwable $previous = null): OutboundRequestRefusedException
    {
        return OutboundRequestRefusedException::forHost(self::host($request), OutboundRequestRefusedException::REASON_SIZE, 'the response exceeds the download limit', $previous);
    }

    private static function guzzleMajorVersion(): int
    {
        // Read at runtime: Core supports Guzzle 7 and 8 from one source.
        $version = constant(ClientInterface::class . '::MAJOR_VERSION');

        return is_int($version) ? $version : 7;
    }

    /**
     * The cURL error number behind a transport failure. Guzzle 7 exposes it in
     * the handler context; Guzzle 8 drops the context, so both are read, with
     * the "cURL error N:" message prefix that every Guzzle version uses as the
     * fallback. Previous exceptions are followed because Guzzle 8 can wrap the
     * transfer error.
     */
    private static function curlErrorNumber(Throwable $throwable): ?int
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransferException && method_exists($current, 'getHandlerContext')) {
                $context = $current->getHandlerContext();

                if (is_array($context) && is_int($context['errno'] ?? null)) {
                    return $context['errno'];
                }
            }

            if (preg_match('/^cURL error (\d+):/', $current->getMessage(), $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private static function host(RequestInterface $request): string
    {
        return strtolower(trim($request->getUri()->getHost(), '[]'));
    }
}
