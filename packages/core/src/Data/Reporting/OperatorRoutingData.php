<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use InvalidArgumentException;

final readonly class OperatorRoutingData
{
    /** @param list<string> $channels */
    public function __construct(
        public array $channels,
        public ?string $owner,
        public ?string $backup,
        public int $escalateAfterSeconds,
    ) {}

    /** @param array<string, mixed> $policy */
    public static function fromPolicy(array $policy): self
    {
        $channels = $policy['channels'] ?? ['log'];
        $owner = $policy['owner'] ?? null;
        $backup = $policy['backup'] ?? null;
        $seconds = $policy['escalate_after_seconds'] ?? 900;
        throw_if(! is_array($channels) || ! array_is_list($channels) || $channels === [], InvalidArgumentException::class, 'Operator channels must be a non-empty list.');
        foreach ($channels as $channel) {
            throw_unless(in_array($channel, ['log', 'health', 'email'], true), InvalidArgumentException::class, 'Operator channel is not supported.');
        }

        foreach ([$owner, $backup] as $operator) {
            throw_if($operator !== null && (! is_string($operator) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $operator) !== 1), InvalidArgumentException::class, 'Operator aliases must be opaque identifiers.');
        }

        throw_if($owner === null && $backup === null, InvalidArgumentException::class, 'Operator routing requires an owner or backup alias.');

        throw_if(! is_int($seconds) || $seconds < 1 || $seconds > 86400, InvalidArgumentException::class, 'Escalation delay must be between 1 and 86400 seconds.');

        return new self(array_values(array_unique($channels)), $owner, $backup, $seconds);
    }
}
