<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * Where a received webhook event is in its life. `Ignored` records a verified
 * event whose type the provider does not handle: unknown types are kept as
 * evidence, never mapped to a default handler.
 */
enum InboundWebhookEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
