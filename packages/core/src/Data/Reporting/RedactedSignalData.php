<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;
use Capell\Core\Support\Reporting\SignalRedactor;
use InvalidArgumentException;
use JsonSerializable;

final readonly class RedactedSignalData implements JsonSerializable
{
    /** @param array<string, mixed> $context */
    private function __construct(
        public string $name,
        public FailureCategory $category,
        public Severity $severity,
        public string $message,
        public string $operatorSummary,
        public string $correlationId,
        public ?string $runId,
        public array $context,
    ) {}

    public static function fromSignal(SignalData $signal): self
    {
        $redactor = new SignalRedactor;

        return new self(
            name: $signal->name,
            category: $signal->category,
            severity: $signal->severity,
            message: $redactor->text($signal->message),
            operatorSummary: $redactor->text($signal->operatorSummary),
            correlationId: $signal->correlationId,
            runId: $signal->runId,
            context: $redactor->context($signal->context),
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromStoredArray(array $payload): self
    {
        foreach (['name', 'category', 'severity', 'message', 'operator_summary', 'correlation_id'] as $key) {
            throw_unless(is_string($payload[$key] ?? null), InvalidArgumentException::class, 'Stored reporting signal is invalid.');
        }

        $runId = $payload['run_id'] ?? null;
        $context = $payload['context'] ?? [];
        throw_if(($runId !== null && ! is_string($runId)) || ! is_array($context), InvalidArgumentException::class, 'Stored reporting signal context is invalid.');
        throw_if(strlen($payload['name']) > 128 || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $payload['name']) !== 1, InvalidArgumentException::class, 'Stored reporting signal identity is invalid.');
        foreach ([$payload['correlation_id'], $runId] as $identifier) {
            throw_if($identifier !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/', $identifier) !== 1, InvalidArgumentException::class, 'Stored reporting signal identity is invalid.');
        }

        $redactor = new SignalRedactor;
        throw_unless(
            $redactor->text($payload['message']) === $payload['message']
                && $redactor->text($payload['operator_summary']) === $payload['operator_summary']
                && $redactor->context($context) === $context,
            InvalidArgumentException::class,
            'Stored reporting signal is not redacted.',
        );

        return new self(
            name: $payload['name'],
            category: FailureCategory::from($payload['category']),
            severity: Severity::from($payload['severity']),
            message: $payload['message'],
            operatorSummary: $payload['operator_summary'],
            correlationId: $payload['correlation_id'],
            runId: $runId,
            context: $context,
        );
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
