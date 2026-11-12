<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Webhooks;

use Capell\Core\Data\Webhooks\InboundWebhookData;
use Capell\Core\Exceptions\InboundWebhookRejectedException;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * One webhook sender, such as a payment or email provider, received through
 * `Route::capellWebhook()`.
 *
 * `verify()` must check the request's signature and a signed timestamp inside a
 * short window before it trusts any of the body; HmacTimestampSignature does both
 * for the common scheme. The receiver then deduplicates on the returned event id,
 * so a replayed delivery has no second effect.
 *
 * `handlerFor()` maps each event type the package acts on to its handler,
 * explicitly. Return null for every other type: the receiver records it as
 * ignored rather than guessing.
 */
interface InboundWebhookProvider
{
    /**
     * A stable name for the sender, such as `payments.stripe`, stored with each
     * event and part of its deduplication key.
     */
    public function key(): string;

    /**
     * @throws InboundWebhookRejectedException when the signature, timestamp or body cannot be trusted
     * @throws InvalidArgumentException when InboundWebhookData refuses the event's id, type or subject; the receiver answers 400
     */
    public function verify(Request $request): InboundWebhookData;

    /**
     * @return class-string<InboundWebhookHandler>|null
     */
    public function handlerFor(string $type): ?string;
}
