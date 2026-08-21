<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\MarketplaceReviewedSelectionOutcome;
use Spatie\LaravelData\Data;
use Throwable;

final class MarketplaceReviewedSelectionOutcomeData extends Data
{
    /** @param list<int> $queuedAttemptIds */
    public function __construct(
        public readonly MarketplaceReviewedSelectionOutcome $outcome,
        public readonly ?string $redirectUrl = null,
        public readonly array $queuedAttemptIds = [],
        public readonly ?string $licenceValidationRule = null,
        public readonly ?string $licenceValidationMessage = null,
        public readonly ?Throwable $failure = null,
    ) {}
}
