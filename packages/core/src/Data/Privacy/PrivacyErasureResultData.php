<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

/**
 * The outcome of running every eraser for a subject: a receipt from each
 * package that finished, and a failure for each that did not. One package
 * failing never stops the others or loses their receipts. A caller must treat
 * any failure as an incomplete erasure.
 */
final readonly class PrivacyErasureResultData
{
    /**
     * @param  list<PrivacyErasureReceiptData>  $receipts
     * @param  list<PrivacyErasureFailureData>  $failures
     */
    public function __construct(
        public array $receipts,
        public array $failures,
    ) {}

    public function isComplete(): bool
    {
        return $this->failures === [];
    }

    /**
     * Whether any package handled the subject, finished or not. False means no
     * registered eraser holds data for the subject, which a caller should tell
     * apart from a completed erasure.
     */
    public function ranAny(): bool
    {
        return $this->receipts !== [] || $this->failures !== [];
    }
}
