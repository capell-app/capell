<?php

declare(strict_types=1);

namespace Capell\Core\Support\Webhooks;

use Capell\Core\Contracts\Webhooks\InboundWebhookHandler;
use Capell\Core\Contracts\Webhooks\InboundWebhookProvider;
use Capell\Core\Enums\InboundWebhookEventStatus;
use Capell\Core\Exceptions\InboundWebhookRejectedException;
use Capell\Core\Jobs\Webhooks\ProcessInboundWebhookEventJob;
use Capell\Core\Models\InboundWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives one webhook delivery for a provider:
 *
 * 1. refuses anything that is not JSON (415);
 * 2. asks the provider to verify the signature and signed timestamp, and answers
 *    a bare 400 when it cannot, without saying which check failed;
 * 3. stores the event once per (provider, event id); a redelivery or replay of a
 *    stored event changes nothing and is acknowledged as a duplicate;
 * 4. records an event type the provider does not map as ignored, and queues
 *    every other new event for its handler, once, after the row is committed.
 *
 * The acknowledgement never echoes the payload or the stored row.
 */
final readonly class InboundWebhookReceiver
{
    public function receive(Request $request, InboundWebhookProvider $provider): JsonResponse
    {
        if (! $request->isJson()) {
            return new JsonResponse(['ok' => false], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            $data = $provider->verify($request);
        } catch (InboundWebhookRejectedException $inboundWebhookRejectedException) {
            Log::warning('Inbound webhook rejected', ['provider' => $provider->key(), 'reason' => $inboundWebhookRejectedException->reason]);

            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $handler = $provider->handlerFor($data->type);
        throw_if($handler !== null && ! is_subclass_of($handler, InboundWebhookHandler::class), LogicException::class, sprintf('The webhook handler for [%s] must implement %s.', $provider->key(), InboundWebhookHandler::class));

        $payloadHash = hash('sha256', $request->getContent());

        $event = InboundWebhookEvent::query()->createOrFirst(
            ['provider' => $provider->key(), 'event_id' => $data->eventId],
            [
                'type' => $data->type,
                'subject' => $data->subject,
                'occurred_at' => $data->occurredAt,
                'status' => $handler === null ? InboundWebhookEventStatus::Ignored : InboundWebhookEventStatus::Received,
                'payload' => $data->payload,
                'payload_hash' => $payloadHash,
                'handler' => $handler,
            ],
        );

        if (! $event->wasRecentlyCreated) {
            if (! hash_equals($event->payload_hash, $payloadHash)) {
                // Providers may resend an event with changed delivery metadata; the
                // stored event stays authoritative and the resend has no effect.
                Log::info('Inbound webhook redelivered with a different body', ['provider' => $provider->key(), 'event' => $event->getKey()]);
            }

            return new JsonResponse(['ok' => true, 'duplicate' => true]);
        }

        if ($handler !== null) {
            dispatch(new ProcessInboundWebhookEventJob((int) $event->getKey()))->afterCommit();
        }

        return new JsonResponse(['ok' => true], Response::HTTP_ACCEPTED);
    }
}
