<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;
use Capell\Core\Support\Reporting\SignalRedactor;
use InvalidArgumentException;
use JsonSerializable;

final readonly class SignalData implements JsonSerializable
{
    public string $message;

    public string $operatorSummary;

    /** @var array<string, mixed> */
    public array $context;

    /** @param array<string, mixed> $context */
    public function __construct(
        public string $name,
        public FailureCategory $category,
        public Severity $severity,
        string $message,
        string $operatorSummary,
        public string $correlationId,
        public ?string $runId = null,
        array $context = [],
    ) {
        throw_if(strlen($name) > 128 || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $name) !== 1, InvalidArgumentException::class, 'A signal name must be a lower-case operational identifier of at most 128 characters.');

        foreach ([$correlationId, $runId] as $identifier) {
            throw_if($identifier !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $identifier) !== 1, InvalidArgumentException::class, 'Correlation and run identifiers must be opaque identifiers of 1 to 128 characters.');
        }

        $redactor = new SignalRedactor;
        $this->message = $redactor->text($message);
        $this->operatorSummary = $redactor->text($operatorSummary);
        $this->context = $redactor->context($context);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->name, $this->category->value, $this->severity->value, $this->correlationId, $this->runId], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'operator_summary' => $this->operatorSummary,
            'correlation_id' => $this->correlationId,
            'run_id' => $this->runId,
            'context' => $this->context,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }

    public function toHuman(): string
    {
        return sprintf('[%s] %s (%s; correlation=%s; run=%s): %s %s', strtoupper($this->severity->value), $this->name, $this->category->value, $this->correlationId, $this->runId ?? '-', $this->message, $this->operatorSummary);
    }
}
