<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Messaging;

use Capell\Core\Data\Messaging\OutboundMessageCatalogueEntryData;
use Capell\Core\Data\Messaging\OutboundMessageSourceData;

/**
 * The list of every kind of message the site can send, whatever the channel. A package that keeps
 * the list binds this interface; Core binds nothing, so callers check `bound()` first.
 *
 * Implementations store no message bodies and no recipient identities.
 *
 * Compatibility: methods are added only in a major Core release.
 */
interface OutboundMessageCatalogue
{
    /**
     * Record a source found in installed code before it was sent. Returns true when it was not
     * known before. Idempotent.
     */
    public function discover(OutboundMessageSourceData $source): bool;

    /**
     * Record a source as it is about to be sent. Returns true the first time it is seen. Never
     * holds or cancels the message.
     */
    public function observe(OutboundMessageSourceData $source): bool;

    /** Record that a message from the source was handed to its channel. */
    public function markSent(OutboundMessageSourceData $source): void;

    /** @return list<OutboundMessageCatalogueEntryData> */
    public function entries(): array;
}
