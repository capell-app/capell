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
    /** @param array<string, bool|float|int|string|null> $metrics */
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
            metrics: is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [],
            durationMilliseconds: (int) ($payload['durationMilliseconds'] ?? 0),
        );
    }

    public function withDuration(int $milliseconds): self
    {
        return new self($this->id, $this->category, $this->status, $this->severity, $this->summary, $this->remediation, $this->metrics, max(0, $milliseconds));
    }

    /** @return array{id: string, category: string, status: string, severity: string, summary: string, remediation: string|null, metrics: array<string, bool|float|int|string|null>, durationMilliseconds: int} */
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
}
