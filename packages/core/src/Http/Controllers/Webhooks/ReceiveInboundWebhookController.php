<?php

declare(strict_types=1);

namespace Capell\Core\Http\Controllers\Webhooks;

use Capell\Core\Contracts\Webhooks\InboundWebhookProvider;
use Capell\Core\Support\Webhooks\InboundWebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * The endpoint `Route::capellWebhook()` registers; the route names its provider.
 */
final readonly class ReceiveInboundWebhookController
{
    public const string PROVIDER_ROUTE_DEFAULT = 'capellWebhookProvider';

    public function __construct(private InboundWebhookReceiver $receiver) {}

    public function __invoke(Request $request): JsonResponse
    {
        $class = $request->route()?->defaults[self::PROVIDER_ROUTE_DEFAULT] ?? null;
        throw_unless(is_string($class) && is_subclass_of($class, InboundWebhookProvider::class), LogicException::class, 'This webhook route does not name an inbound webhook provider.');

        $provider = resolve($class);
        throw_unless($provider instanceof InboundWebhookProvider, LogicException::class, 'This webhook route does not name an inbound webhook provider.');

        return $this->receiver->receive($request, $provider);
    }
}
