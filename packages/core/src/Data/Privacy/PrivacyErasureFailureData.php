<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

/**
 * One package whose erasure did not finish cleanly. It carries a fixed code and
 * the exception class, never the exception message, which can contain the
 * person's data. The package may already have erased some data, so the request
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

    public function __construct(
        public string $contributor,
        public string $code,
        public ?string $exceptionClass = null,
    ) {}

    /**
     * @return array{contributor: string, code: string, exception_class: string|null}
     */
    public function toArray(): array
    {
        return ['contributor' => $this->contributor, 'code' => $this->code, 'exception_class' => $this->exceptionClass];
    }
}
