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
 *
 * Work that is not a route of its own, such as a Livewire action, spends the same buckets
 * with hit(). Its subject is a server-resolved identity, such as the record being commented
 * on, never a value the client submitted:
 *
 *     $throttle->hit($request, ['thread' => $post->getMorphClass() . ':' . $post->getKey()]);
 */
final class PublicEndpointThrottle
{
    /** How many times the per-parameter limit one client may spend across every parameter value. */
    public const int CEILING_MULTIPLIER = 5;

    private const string PURPOSE_PATTERN = '/^capell-[a-z0-9]+(?:-[a-z0-9]+)+$/';

    private int $maxAttempts = 60;

    private int $decaySeconds = 60;

    private bool $keyedByActor = true;

    private bool $keyedByClientAddress = true;

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
     * Key on the signed-in actor alone, so changing address does not reset the bucket, as for
     * guesses at a signed-in user's current password. Requests without an actor share one
     * address-free bucket per route, so use this only where an actor is always present.
     */
    public function ignoringClientAddress(): self
    {
        $this->keyedByClientAddress = false;

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

    /**
     * @param  array<string, int|string>  $subject  server-resolved identities that each get their own bucket
     * @return list<Limit>
     */
    public function limitsFor(Request $request, array $subject = []): array
    {
        $limits = [new Limit($this->keyFor($request, subject: $subject), $this->maxAttempts, $this->decaySeconds)];

        if ($this->routeParameters !== [] || $subject !== []) {
            $limits[] = new Limit($this->keyFor($request, withRouteParameters: false), $this->maxAttempts * self::CEILING_MULTIPLIER, $this->decaySeconds);
        }

        return $limits;
    }

    /** @param  array<string, int|string>  $subject */
    public function keyFor(Request $request, bool $withRouteParameters = true, array $subject = []): string
    {
        throw_if(! $this->keyedByClientAddress && ! $this->keyedByActor, InvalidArgumentException::class, sprintf('Throttle [%s] must key on the client address, the actor or both.', $this->purpose));

        $route = $request->route();
        $parts = [
            $this->purpose,
            $this->keyedByClientAddress ? (string) $request->ip() : '',
            $route?->getName() ?? $route?->uri() ?? $request->path(),
        ];

        foreach ($withRouteParameters ? $this->routeParameters : [] as $name) {
            $value = $route?->parameter($name);
            $parts[] = $name . '=' . (is_scalar($value) ? (string) $value : (is_object($value) && method_exists($value, 'getRouteKey') ? (string) $value->getRouteKey() : ''));
        }

        foreach ($subject as $name => $value) {
            throw_if($name === '', InvalidArgumentException::class, 'A throttle subject needs a name.');

            $parts[] = 'subject.' . $name . '=' . $value;
        }

        if ($this->keyedByActor) {
            $parts[] = 'actor=' . ($request->user()?->getAuthIdentifier() ?? '');
        }

        return $this->purpose . ':' . hash('sha256', implode('|', $parts));
    }

    /**
     * Spend one attempt from every bucket. Returns false, and gives back every attempt it took,
     * when the request is over a limit.
     *
     * The count each atomic increment returns decides, not the earlier check: two requests can
     * both see room and race past it, and only the increment shows which one went over.
     *
     * @param  array<string, int|string>  $subject
     */
    public function hit(Request $request, array $subject = []): bool
    {
        $limits = $this->limitsFor($request, $subject);

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                return false;
            }
        }

        $taken = [];

        foreach ($limits as $limit) {
            $taken[] = $limit;

            if (RateLimiter::hit($limit->key, $limit->decaySeconds) > $limit->maxAttempts) {
                foreach ($taken as $spent) {
                    RateLimiter::decrement($spent->key, $spent->decaySeconds);
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Seconds until every full bucket has room again; zero when none is full.
     *
     * @param  array<string, int|string>  $subject
     */
    public function availableIn(Request $request, array $subject = []): int
    {
        $seconds = 0;

        foreach ($this->limitsFor($request, $subject) as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                $seconds = max($seconds, RateLimiter::availableIn($limit->key));
            }
        }

        return $seconds;
    }

    /**
     * Forget the subject's own bucket, as after a correct password. The shared ceiling keeps its count.
     *
     * @param  array<string, int|string>  $subject
     */
    public function clear(Request $request, array $subject = []): void
    {
        RateLimiter::clear($this->limitsFor($request, $subject)[0]->key);
    }

    public function register(): void
    {
        RateLimiter::for($this->purpose, fn (Request $request): array => $this->limitsFor($request));
    }
}
