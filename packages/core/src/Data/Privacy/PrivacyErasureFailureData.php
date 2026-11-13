<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

/**
 * One package whose erasure did not finish cleanly. It carries a fixed code and
 * the exception class, never the exception message, which can contain the
 * person's data (the exception itself goes to the application's exception
 * handler). When Core refused the receipt the package returned, the failure
 * keeps it, so an operator can see what the package says it erased. The package may already have erased some data, so the request
 * is incomplete until it is run again or resolved by hand.
 */
final readonly class PrivacyErasureFailureData
{
    /** The eraser threw. */
    public const string CODE_ERASER_FAILED = 'eraser_failed';

    /** The eraser ran but returned a receipt for another package. */
    public const string CODE_RECEIPT_CONTRIBUTOR = 'receipt_contributor';

    /** The eraser ran but kept a suppression marker it did not declare. */
    public const string CODE_UNDECLARED_MARKER = 'undeclared_suppression_marker';

    /** The caller named a package that has no registered eraser. */
    public const string CODE_CONTRIBUTOR_MISSING = 'contributor_missing';

    public function __construct(
        public string $contributor,
        public string $code,
        public ?string $exceptionClass = null,
        public ?PrivacyErasureReceiptData $rejectedReceipt = null,
    ) {}

    /**
     * @return array{contributor: string, code: string, exception_class: string|null, rejected_receipt: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'contributor' => $this->contributor,
            'code' => $this->code,
            'exception_class' => $this->exceptionClass,
            'rejected_receipt' => $this->rejectedReceipt?->toArray(),
        ];
    }
}
