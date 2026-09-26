<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;
use InvalidArgumentException;

final readonly class SignalData
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $name,
        public FailureCategory $category,
        public Severity $severity,
        public string $message,
        public string $operatorSummary,
        public string $correlationId,
        public ?string $runId = null,
        public array $context = [],
    ) {
        throw_if(strlen($name) > 128 || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $name) !== 1, InvalidArgumentException::class, 'A signal name must be a lower-case operational identifier of at most 128 characters.');

        foreach ([$correlationId, $runId] as $identifier) {
            throw_if($identifier !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $identifier) !== 1, InvalidArgumentException::class, 'Correlation and run identifiers must be opaque identifiers of 1 to 128 characters.');
        }

    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->name, $this->category->value, $this->severity->value, $this->correlationId, $this->runId], JSON_THROW_ON_ERROR));
    }
}
