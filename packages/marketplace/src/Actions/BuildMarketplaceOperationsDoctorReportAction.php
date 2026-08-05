<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Marketplace\Data\MarketplaceReadinessCheckData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceComposerAuthWorkspace;
use Capell\Marketplace\Support\MarketplaceQueueTimeoutChain;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMarketplaceOperationsDoctorReportAction
{
    use AsFake;
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
        $checks = collect([$this->environmentReadinessCheck(), $schemaCheck, $this->authWorkspaceCheck()]);

        if ($schemaCheck->passed) {
            $checks->push($this->stuckOperationsCheck($staleAfterMinutes));
            $checks->push($this->failedOperationsCheck());
            $checks->push($this->queueRetryAfterCheck());
        }

        // Severity is what decides the report status, not `passed` alone: a
        // check exists to describe a condition honestly, and only a Critical
        // one may red-light the health gates and the command's exit code. A
        // check that must fail the report therefore has to declare itself
        // Critical rather than borrow the effect from an aggregate that
        // ignores severity.
        return new DoctorReportData(
            status: $checks->contains(static fn (DoctorCheckResultData $check): bool => $check->isCriticalFailure())
                ? 'failed'
                : 'passed',
            checks: $checks,
        );
    }

    /**
     * Surface the canonical readiness evaluation, so the doctor and the admin
     * describe the host in the same words.
     *
     * A manual-only host is a supported hosting shape rather than a fault, so it
     * must not fail the report and take the command's exit code with it. Only a
     * blocked host — one that is misconfigured — does that.
     */
    private function environmentReadinessCheck(): DoctorCheckResultData
    {
        $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run();

        $failed = $readiness->failedChecks();
        $warned = $readiness->warnedChecks();

        return new DoctorCheckResultData(
            label: (string) __('capell-marketplace::marketplace.operations.doctor_readiness_label'),
            passed: ! $readiness->isBlocked(),
            message: (string) __('capell-marketplace::marketplace.operations.doctor_readiness_message', [
                'capability' => $readiness->capability->getLabel(),
            ]),
            remediation: $failed === []
                ? null
                : implode(' ', array_values(array_unique(array_filter(array_map(
                    static fn (MarketplaceReadinessCheckData $check): ?string => $check->remediation,
                    $failed,
                ))))),
            id: 'marketplace.operations.environment-readiness',
            severity: DoctorCheckSeverity::Critical,
            evidence: [
                'capability' => $readiness->capability->value,
                'failed_checks' => array_map(
                    static fn (MarketplaceReadinessCheckData $check): string => $check->key,
                    $failed,
                ),
                'warned_checks' => array_map(
                    static fn (MarketplaceReadinessCheckData $check): string => $check->key,
                    $warned,
                ),
                'docs_path' => EvaluateMarketplaceEnvironmentReadinessAction::DOCS_PATH,
            ],
        );
    }

    /**
     * An install killed mid-Composer never reaches the cleanup that removes its
     * throwaway Composer home, and each one holds an auth file. The runner sweeps
     * them at the start of the next run, so this is a warning about accumulated
     * debris rather than something the operator must act on.
     *
     * Debris present is a genuine `false`, and it says so. It does not take the
     * report's status with it because it is Warning-severity and the aggregate
     * reads severity.
     */
    private function authWorkspaceCheck(): DoctorCheckResultData
    {
        $stale = new MarketplaceComposerAuthWorkspace()->stale();

        return new DoctorCheckResultData(
            label: (string) __('capell-marketplace::marketplace.operations.doctor_auth_files_label'),
            passed: $stale === [],
            message: $stale === []
                ? (string) __('capell-marketplace::marketplace.operations.doctor_auth_files_healthy')
                : (string) __('capell-marketplace::marketplace.operations.doctor_auth_files_unhealthy', [
                    'count' => count($stale),
                ]),
            remediation: $stale === []
                ? null
                : (string) __('capell-marketplace::marketplace.operations.doctor_auth_files_remediation'),
            id: 'marketplace.operations.composer-auth-files',
            severity: DoctorCheckSeverity::Warning,
            evidence: [
                'count' => count($stale),
                'stale_after_seconds' => MarketplaceComposerAuthWorkspace::staleAfterSeconds(),
            ],
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

    /**
     * Critical, not Warning: unresolved failed and timed-out operations are the
     * one thing here whose remediation genuinely asks the operator to go and do
     * something, and nothing clears them on its own. Under severity-aware
     * aggregation, Warning would have silently stopped failing the report.
     */
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
            severity: DoctorCheckSeverity::Critical,
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
        $chain = MarketplaceQueueTimeoutChain::resolve();
        $isSafe = $chain->isSafe();

        return new DoctorCheckResultData(
            label: (string) __('capell-marketplace::marketplace.operations.doctor_queue_label'),
            passed: $isSafe,
            message: $isSafe
                ? (string) __('capell-marketplace::marketplace.operations.doctor_queue_healthy')
                : (string) __('capell-marketplace::marketplace.operations.doctor_queue_unhealthy', [
                    'seconds' => $chain->retryAfterSeconds,
                    'job_timeout' => $chain->jobTimeoutSeconds,
                ]),
            remediation: $isSafe
                ? null
                : (string) __('capell-marketplace::marketplace.operations.doctor_queue_remediation'),
            id: 'marketplace.operations.queue-retry-after',
            severity: DoctorCheckSeverity::Critical,
            evidence: [
                'connection' => $chain->connectionName,
                'retry_after_seconds' => $chain->retryAfterSeconds,
                'job_timeout_seconds' => $chain->jobTimeoutSeconds,
            ],
        );
    }
}
