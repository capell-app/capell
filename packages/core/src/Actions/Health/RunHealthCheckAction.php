<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Health;

use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use Capell\Core\Support\Health\HealthCheckRegistry;
use Capell\Core\Support\Health\HealthSummarySanitizer;
use InvalidArgumentException;
use Throwable;

final readonly class RunHealthCheckAction
{
    public function __construct(private HealthCheckRegistry $registry, private HealthSummarySanitizer $sanitizer) {}

    public function handle(string $id): HealthCheckResultData
    {
        $check = $this->registry->find($id);
        throw_if(! $check instanceof HealthCheck, InvalidArgumentException::class, sprintf('Unknown health check [%s].', $id));
        $started = hrtime(true);

        try {
            $result = $check->run();
            throw_if($result->id !== $check->id() || $result->category !== $check->category(), InvalidArgumentException::class, 'Health check result identity must match its registered check.');

            return $this->sanitize($result)->withDuration($this->elapsed($started));
        } catch (Throwable $throwable) {
            return new HealthCheckResultData(
                id: $check->id(),
                category: $check->category(),
                status: HealthStatus::Error,
                severity: HealthSeverity::Critical,
                summary: 'Check raised ' . $throwable::class . '.',
                remediation: 'Inspect the check implementation and application logs for protected diagnostic detail.',
                durationMilliseconds: $this->elapsed($started),
            );
        }
    }

    private function sanitize(HealthCheckResultData $result): HealthCheckResultData
    {
        return new HealthCheckResultData($result->id, $result->category, $result->status, $result->severity, $this->sanitizer->sanitize($result->summary), $result->remediation === null ? null : $this->sanitizer->sanitize($result->remediation), $result->metrics, $result->durationMilliseconds);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
