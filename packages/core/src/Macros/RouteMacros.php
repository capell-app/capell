<?php

declare(strict_types=1);

namespace Capell\Core\Macros;

use Capell\Core\Contracts\Webhooks\InboundWebhookProvider;
use Capell\Core\Http\Controllers\Webhooks\ReceiveInboundWebhookController;
use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use InvalidArgumentException;

/**
 * @mixin Router
 */
final class RouteMacros
{
    /**
     * Registers a webhook endpoint: POST only, exempt from CSRF (the provider's
     * signature replaces it), throttled per client address and provider, and handled by the
     * Core receiver for the given provider.
     *
     * The throttle keys on the client address, so the application must trust its
     * load balancer or proxy (Laravel's TrustProxies) for the address to be the
     * sender's. Behind an untrusted proxy every request shares the proxy's
     * address, and unsigned junk would use up the provider's own allowance.
     *
     * Usage: `Route::capellWebhook('webhooks/stripe', StripeWebhookProvider::class)`.
     */
    public function capellWebhook(): Closure
    {
        return function (string $uri, string $provider, int $perMinute = 120): Route {
            throw_unless(is_subclass_of($provider, InboundWebhookProvider::class), InvalidArgumentException::class, sprintf('A webhook route needs a class that implements %s.', InboundWebhookProvider::class));
            throw_if($perMinute < 1, InvalidArgumentException::class, 'A webhook route must allow at least one request a minute.');

            return $this->post($uri, ReceiveInboundWebhookController::class)
                ->defaults(ReceiveInboundWebhookController::PROVIDER_ROUTE_DEFAULT, $provider)
                ->withoutMiddleware([
                    PreventRequestForgery::class,
                    ValidateCsrfToken::class,
                    VerifyCsrfToken::class,
                ])
                ->middleware(sprintf('throttle:%d,1,capell-webhook-%s:', $perMinute, substr(hash('sha256', $provider), 0, 16)));
        };
    }
}
