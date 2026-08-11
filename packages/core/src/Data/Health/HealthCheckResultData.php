<?php

declare(strict_types=1);

namespace Capell\Core\Data\Health;

use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Data;

final class HealthCheckResultData extends Data
{
    /** @param array<string, bool|float|int|null> $metrics */
    public function __construct(
        public string $id,
        public string $category,
        public HealthStatus $status,
        public HealthSeverity $severity,
        public string $summary,
        public ?string $remediation = null,
        public array $metrics = [],
        public int $durationMilliseconds = 0,
    ) {
        throw_if($id === '' || $category === '' || $summary === '', InvalidArgumentException::class, 'Health results require an ID, category, and summary.');
        throw_if($durationMilliseconds < 0, InvalidArgumentException::class, 'Health result duration cannot be negative.');

        foreach ($metrics as $name => $value) {
            throw_unless(is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9]*(?:[._-][A-Za-z0-9]+)*$/', $name) === 1 && (is_bool($value) || is_float($value) || is_int($value) || $value === null), InvalidArgumentException::class, 'Health metrics must contain only stable named numeric, boolean, or null values.');
            throw_if(is_float($value) && ! is_finite($value), InvalidArgumentException::class, 'Health metrics require finite numeric values.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) ($payload['id'] ?? ''),
            category: (string) ($payload['category'] ?? ''),
            status: HealthStatus::from((string) ($payload['status'] ?? '')),
            severity: HealthSeverity::from((string) ($payload['severity'] ?? '')),
            summary: (string) ($payload['summary'] ?? ''),
            remediation: isset($payload['remediation']) ? (string) $payload['remediation'] : null,
            metrics: self::metricsFromPayload($payload['metrics'] ?? []),
            durationMilliseconds: (int) ($payload['durationMilliseconds'] ?? 0),
        );
    }

    public function withDuration(int $milliseconds): self
    {
        return new self($this->id, $this->category, $this->status, $this->severity, $this->summary, $this->remediation, $this->metrics, max(0, $milliseconds));
    }

    /** @return array{id: string, category: string, status: string, severity: string, summary: string, remediation: string|null, metrics: array<string, bool|float|int|null>, durationMilliseconds: int} */
    #[Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'remediation' => $this->remediation,
            'metrics' => $this->metrics,
            'durationMilliseconds' => $this->durationMilliseconds,
        ];
    }

    /** @return array<string, bool|float|int|null> */
    private static function metricsFromPayload(mixed $metrics): array
    {
        throw_unless(is_array($metrics), InvalidArgumentException::class, 'Health metrics must be a keyed scalar map.');
        $validated = [];

        foreach ($metrics as $name => $value) {
            throw_unless(is_string($name) && (is_bool($value) || is_float($value) || is_int($value) || $value === null), InvalidArgumentException::class, 'Health metrics must contain only numeric, boolean, or null values.');
            $validated[$name] = $value;
        }

        return $validated;
    }
}
