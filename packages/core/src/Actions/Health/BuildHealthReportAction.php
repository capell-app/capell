<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Health;

use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Data\Health\HealthReportData;
use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Health\HealthCheckRegistry;
use Capell\Core\Support\Health\HealthSummarySanitizer;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;
use UnexpectedValueException;

final readonly class BuildHealthReportAction
{
    public function __construct(private HealthCheckRegistry $registry, private ProcessFactoryInterface $processFactory, private HealthSummarySanitizer $sanitizer) {}

    public function handle(): HealthReportData
    {
        $results = [];
        foreach ($this->registry->checks() as $check) {
            $started = hrtime(true);
            try {
                $process = $this->processFactory->make([...new RuntimeBinaryResolver()->php(), 'artisan', 'capell:health-check', $check->id()], base_path(), ArtisanProcessEnvironment::prepare(ComposerProcessEnvironment::forInstall($_SERVER)));
                $process->setTimeout($check->timeoutSeconds());
                $process->run();
                $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                throw_unless(is_array($payload), UnexpectedValueException::class, 'Health check returned an invalid report.');
                $result = HealthCheckResultData::fromArray($payload);
                throw_unless($result->id === $check->id() && $result->category === $check->category(), UnexpectedValueException::class, 'Health check returned a mismatched identity.');
                $results[] = new HealthCheckResultData(
                    $result->id,
                    $result->category,
                    $result->status,
                    $result->severity,
                    $this->sanitizer->sanitize($result->summary),
                    $result->remediation === null ? null : $this->sanitizer->sanitize($result->remediation),
                    $result->metrics,
                    $this->elapsed($started),
                );
            } catch (Throwable $throwable) {
                $timedOut = $throwable instanceof ProcessTimedOutException;
                $results[] = new HealthCheckResultData(
                    id: $check->id(),
                    category: $check->category(),
                    status: $timedOut ? HealthStatus::TimedOut : HealthStatus::Error,
                    severity: HealthSeverity::Critical,
                    summary: $timedOut ? sprintf('Check exceeded its %d second timeout.', $check->timeoutSeconds()) : 'Check execution failed (' . $throwable::class . ').',
                    remediation: 'Inspect the check implementation and application logs for protected diagnostic detail.',
                    durationMilliseconds: $this->elapsed($started),
                );
            }
        }

        return new HealthReportData($results);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
