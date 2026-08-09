<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class MarketplaceBulkUpdateResultData extends Data
{
    /**
     * @param  array<string, int>  $queuedAttemptIds  Composer name => attempt id.
     * @param  array<string, string>  $skipped  Composer name => why it was not queued.
     */
    public function __construct(
        public readonly int $requestedCount,
        public readonly array $queuedAttemptIds = [],
        public readonly array $skipped = [],
    ) {}

    public function queuedCount(): int
    {
        return count($this->queuedAttemptIds);
    }

    public function queuedAnything(): bool
    {
        return $this->queuedAttemptIds !== [];
    }

    /**
     * The summary an operator gets once the queueing pass is done.
     *
     * Counts first and reasons after, because the first question after a bulk
     * action is "did all of them go", and the skipped list is only interesting
     * once the answer is no.
     */
    public function summaryBody(): string
    {
        $body = (string) __('capell-marketplace::marketplace.updates.bulk_queued_body', [
            'queued' => $this->queuedCount(),
            'requested' => $this->requestedCount,
        ]);

        foreach ($this->skipped as $composerName => $reason) {
            $body .= "\n" . __('capell-marketplace::marketplace.updates.bulk_skipped', [
                'package' => $composerName,
                'reason' => $reason,
            ]);
        }

        return $body;
    }
}
