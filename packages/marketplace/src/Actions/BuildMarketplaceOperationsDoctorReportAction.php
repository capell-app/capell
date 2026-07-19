<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMarketplaceOperationsDoctorReportAction
{
    use AsObject;

    private const array RUNTIME_COLUMNS = [
        'idempotency_key',
        'current_stage',
        'heartbeat_at',
        'attempt_count',
        'runtime_ms',
        'peak_memory_bytes',
        'query_count',
        'stage_telemetry',
        'failure_context',
    ];

    public function handle(int $staleAfterMinutes = 15): DoctorReportData
    {
        $schemaCheck = $this->schemaCheck();
        $checks = collect([$schemaCheck]);

        if ($schemaCheck->passed) {
            $checks->push($this->stuckOperationsCheck($staleAfterMinutes));
            $checks->push($this->failedOperationsCheck());
            $checks->push($this->queueRetryAfterCheck());
        }

        return new DoctorReportData(
            status: $checks->every(static fn (DoctorCheckResultData $check): bool => $check->passed) ? 'passed' : 'failed',
            checks: $checks,
        );
    }

    private function schemaCheck(): DoctorCheckResultData
    {
        $tableExists = Schema::hasTable('marketplace_install_attempts');
        $missingColumns = $tableExists
            ? array_values(array_filter(
                self::RUNTIME_COLUMNS,
                static fn (string $column): bool => ! Schema::hasColumn('marketplace_install_attempts', $column),
            ))
            : self::RUNTIME_COLUMNS;

        return new DoctorCheckResultData(
            label: (string) __('capell-marketplace::marketplace.operations.doctor_schema_label'),
            passed: $tableExists && $missingColumns === [],
            message: $tableExists && $missingColumns === []
                ? (string) __('capell-marketplace::marketplace.operations.doctor_schema_healthy')
                : (string) __('capell-marketplace::marketplace.operations.doctor_schema_unhealthy'),
            remediation: $tableExists && $missingColumns === []
                ? null
                : (string) __('capell-marketplace::marketplace.operations.doctor_run_migrations'),
            id: 'marketplace.operations.schema',
            severity: DoctorCheckSeverity::Critical,
            evidence: ['missing_columns' => $missingColumns],
        );
    }

    private function stuckOperationsCheck(int $staleAfterMinutes): DoctorCheckResultData
    {
        $stuckOperations = FindStuckMarketplaceInstallOperationsAction::run($staleAfterMinutes);

        return new DoctorCheckResultData(
            label: (string) __('capell-marketplace::marketplace.operations.doctor_stuck_label'),
            passed: $stuckOperations->isEmpty(),
            message: $stuckOperations->isEmpty()
                ? (string) __('capell-marketplace::marketplace.operations.doctor_stuck_healthy')
                : (string) __('capell-marketplace::marketplace.operations.doctor_stuck_unhealthy', ['count' => $stuckOperations->count()]),
            remediation: $stuckOperations->isEmpty()
                ? null
                : (string) __('capell-marketplace::marketplace.operations.doctor_review_operations'),
            id: 'marketplace.operations.stuck',
            severity: DoctorCheckSeverity::Critical,
            evidence: [
                'count' => $stuckOperations->count(),
                'operation_ids' => $stuckOperations->modelKeys(),
                'stale_after_minutes' => max(1, $staleAfterMinutes),
            ],
        );
    }

    private function failedOperationsCheck(): DoctorCheckResultData
    {
        $failed = MarketplaceInstallAttempt::query()
            ->whereNull('resolved_at')
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Failed->value,
                MarketplaceInstallIntentStatus::TimedOut->value,
            ])
            ->get(['id', 'status', 'failure_type', 'failure_stage']);

        return new DoctorCheckResultData(
            label: (string) __('capell-marketplace::marketplace.operations.doctor_failed_label'),
            passed: $failed->isEmpty(),
            message: $failed->isEmpty()
                ? (string) __('capell-marketplace::marketplace.operations.doctor_failed_healthy')
                : (string) __('capell-marketplace::marketplace.operations.doctor_failed_unhealthy', ['count' => $failed->count()]),
            remediation: $failed->isEmpty()
                ? null
                : (string) __('capell-marketplace::marketplace.operations.doctor_review_operations'),
            id: 'marketplace.operations.failed',
            severity: DoctorCheckSeverity::Warning,
            evidence: [
                'count' => $failed->count(),
                'operations' => $failed->map(static fn (MarketplaceInstallAttempt $attempt): array => [
                    'id' => $attempt->getKey(),
                    'status' => $attempt->status->value,
                    'failure_type' => $attempt->failure_type,
                    'failure_stage' => $attempt->failure_stage,
                ])->all(),
            ],
        );
    }

    private function queueRetryAfterCheck(): DoctorCheckResultData
    {
        $connectionName = (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database');
        $retryAfter = config('queue.connections.' . $connectionName . '.retry_after');
        $retryAfterSeconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $isSafe = $retryAfterSeconds === null || $retryAfterSeconds > 720;

        return new DoctorCheckResultData(
            label: (string) __('capell-marketplace::marketplace.operations.doctor_queue_label'),
            passed: $isSafe,
            message: $isSafe
                ? (string) __('capell-marketplace::marketplace.operations.doctor_queue_healthy')
                : (string) __('capell-marketplace::marketplace.operations.doctor_queue_unhealthy', ['seconds' => $retryAfterSeconds]),
            remediation: $isSafe
                ? null
                : (string) __('capell-marketplace::marketplace.operations.doctor_queue_remediation'),
            id: 'marketplace.operations.queue-retry-after',
            severity: DoctorCheckSeverity::Critical,
            evidence: [
                'connection' => $connectionName,
                'retry_after_seconds' => $retryAfterSeconds,
                'job_timeout_seconds' => 720,
            ],
        );
    }
}
