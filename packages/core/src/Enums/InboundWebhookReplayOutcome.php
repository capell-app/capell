<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/** What happened when a stored inbound webhook event was asked to replay. */
enum InboundWebhookReplayOutcome: string
{
    /** The event is queued for its handler again. */
    case Queued = 'queued';

    /** The provider does not handle the event's type, so there is no handler to run. */
    case Ignored = 'ignored';

    /** The handler already applied the event; replaying it again needs an explicit force. */
    case AlreadyProcessed = 'already-processed';

    /** A job for the event is already queued or running, so a second one was not queued. */
    case AlreadyQueued = 'already-queued';

    public function queued(): bool
    {
        return $this === self::Queued;
    }
}
