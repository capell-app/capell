<?php

declare(strict_types=1);

namespace Capell\Core\Data\Messaging;

use Capell\Core\Enums\Messaging\OutboundMessageKind;
use Spatie\LaravelData\Data;

/**
 * One kind of message the site can send: the class that builds it and the channel it goes out on,
 * as Laravel reports the channel (`mail`, `vonage`, `slack`, `broadcast` or a custom channel class).
 * The channel is null while it is unknown, such as a notification found before it was ever sent,
 * because its channels come from `via()` and depend on who receives it.
 */
final class OutboundMessageSourceData extends Data
{
    public const string UNKNOWN_CHANNEL = '*';

    public function __construct(
        public OutboundMessageKind $kind,
        public ?string $sourceClass,
        public ?string $channel,
        public ?string $owningPackage = null,
    ) {}

    public static function unattributed(string $channel): self
    {
        return new self(OutboundMessageKind::Unattributed, null, $channel);
    }

    /**
     * The stable key a catalogue, a log row and a later rate limit share: `kind|class|channel`.
     */
    public function key(): string
    {
        return implode('|', [
            $this->kind->value,
            $this->sourceClass ?? '-',
            $this->channel ?? self::UNKNOWN_CHANNEL,
        ]);
    }

    public function withChannel(string $channel): self
    {
        return new self($this->kind, $this->sourceClass, $channel, $this->owningPackage);
    }
}
