<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Builds the rate limit for a public endpoint from facts the client cannot choose.
 *
 * The bucket key is the purpose, the client IP and the matched route, plus the signed-in
 * actor when there is one. Named route parameters may be added. Request-body and query
 * fields never are, because a client that picks the key picks a fresh bucket on every
 * request. Laravel's limiter increments the bucket atomically in the cache store.
 *
 * The client IP is only as trustworthy as the application's trusted proxy configuration:
 * trusting every proxy lets a client choose its own address through X-Forwarded-For.
 *
 * Register once in a provider's boot method, then guard the route with `throttle:<purpose>`:
 *
 *     PublicEndpointThrottle::for('capell-forms-submit')->allow(5)->perSeconds(60)->register();
 */
final class PublicEndpointThrottle
{
    /** How many times the per-parameter limit one client may spend across every parameter value. */
    public const int CEILING_MULTIPLIER = 5;

    private const string PURPOSE_PATTERN = '/^capell-[a-z0-9]+(?:-[a-z0-9]+)+$/';

    private int $maxAttempts = 60;

    private int $decaySeconds = 60;

    private bool $keyedByActor = true;

    /** @var list<string> */
    private array $routeParameters = [];

    private function __construct(public readonly string $purpose) {}

    /** @param  string  $purpose  a `capell-<package>-<purpose>` name, as declared in the manifest's `security.throttles` */
    public static function for(string $purpose): self
    {
        throw_unless(
            preg_match(self::PURPOSE_PATTERN, $purpose) === 1,
            InvalidArgumentException::class,
            sprintf('Throttle purpose [%s] must be a capell-<package>-<purpose> name.', $purpose),
        );

        return new self($purpose);
    }

    public function allow(int $maxAttempts): self
    {
        throw_unless($maxAttempts > 0, InvalidArgumentException::class, 'A public endpoint throttle must allow at least one attempt.');

        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    public function perSeconds(int $decaySeconds): self
    {
        throw_unless($decaySeconds > 0, InvalidArgumentException::class, 'A public endpoint throttle window must be at least one second.');

        $this->decaySeconds = $decaySeconds;

        return $this;
    }

    /** Share one bucket between every visitor on an IP, even when signed in. */
    public function ignoringActor(): self
    {
        $this->keyedByActor = false;

        return $this;
    }

    /**
     * Give each value of a named route parameter, such as the form being posted to, its own
     * bucket. The throttle runs before route binding, so the value is the raw path segment
     * and a client can invent values. A second, parameter-free bucket therefore caps the
     * client at CEILING_MULTIPLIER times the limit across every value.
     */
    public function perRouteParameter(string $name): self
    {
        throw_if($name === '', InvalidArgumentException::class, 'A throttle route parameter needs a name.');

        $this->routeParameters[] = $name;

        return $this;
    }

    /** @return list<Limit> */
    public function limitsFor(Request $request): array
    {
        $limits = [new Limit($this->keyFor($request), $this->maxAttempts, $this->decaySeconds)];

        if ($this->routeParameters !== []) {
            $limits[] = new Limit($this->keyFor($request, withRouteParameters: false), $this->maxAttempts * self::CEILING_MULTIPLIER, $this->decaySeconds);
        }

        return $limits;
    }

    public function keyFor(Request $request, bool $withRouteParameters = true): string
    {
        $route = $request->route();
        $parts = [
            $this->purpose,
            (string) $request->ip(),
            $route?->getName() ?? $route?->uri() ?? $request->path(),
        ];

        foreach ($withRouteParameters ? $this->routeParameters : [] as $name) {
            $value = $route?->parameter($name);
            $parts[] = $name . '=' . (is_scalar($value) ? (string) $value : (is_object($value) && method_exists($value, 'getRouteKey') ? (string) $value->getRouteKey() : ''));
        }

        if ($this->keyedByActor) {
            $parts[] = 'actor=' . ($request->user()?->getAuthIdentifier() ?? '');
        }

        return $this->purpose . ':' . hash('sha256', implode('|', $parts));
    }

    public function register(): void
    {
        RateLimiter::for($this->purpose, fn (Request $request): array => $this->limitsFor($request));
    }
}
