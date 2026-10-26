<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Diagnostics;

use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Models\FailedJob;
use Capell\Core\Actions\Packages\BuildPackageCapabilityGraphAction;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildOptimizerReadinessDiagnosticsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ProcessFactoryInterface $processFactory) {}

    /**
     * @return list<DiagnosticCheckData>
     */
    public function handle(): array
    {
        $active = $this->pipelineConfigured();
        $profile = $this->latestProfileGenerationCheck();

        return [
            $active ? $this->commandCheck(
                ['node', '--version'],
                (string) __('capell-admin::diagnostics.node_runtime'),
                (string) __('capell-admin::diagnostics.node_runtime_remediation'),
            ) : $this->optionalCheck((string) __('capell-admin::diagnostics.node_runtime')),
            $active ? $this->commandCheck(
                ['npx', 'playwright', '--version'],
                (string) __('capell-admin::diagnostics.playwright_runtime'),
                (string) __('capell-admin::diagnostics.playwright_runtime_remediation'),
            ) : $this->optionalCheck((string) __('capell-admin::diagnostics.playwright_runtime')),
            $active ? $profile : $this->optionalCheck((string) __('capell-admin::diagnostics.latest_optimizer_profile')),
            $this->failedCriticalCssJobsCheck(),
            $this->pendingCriticalCssJobsCheck(),
        ];
    }

    private function optionalCheck(string $label): DiagnosticCheckData
    {
        return new DiagnosticCheckData(
            status: 'not_configured',
            label: $label,
            detail: (string) __('capell-admin::diagnostics.optimizer_optional'),
        );
    }

    private function pipelineConfigured(): bool
    {
        $capabilities = BuildPackageCapabilityGraphAction::run();
        if ($capabilities->packagesWith(PackageCapability::FrontendOptimizer) !== []
            || $capabilities->packagesWith(PackageCapability::FrontendOptimizerConsole) !== []
            || (is_array(config('capell-frontend-optimizer')) && config('capell-frontend-optimizer') !== [])
        ) {
            return true;
        }

        foreach ([storage_path('app/capell/frontend-optimizer'), public_path('frontend-optimizer')] as $path) {
            if (is_dir($path) && File::allFiles($path) !== []) {
                return true;
            }
        }

        // In-flight legacy work is evidence of an active pipeline even when no
        // optional package is registered. Failed work is always reported below.
        return $this->pendingCriticalCssJobsQuery()?->exists() ?? false;
    }

    /**
     * @param  list<string>  $command
     */
    private function commandCheck(array $command, string $label, string $remediation): DiagnosticCheckData
    {
        try {
            $process = $this->processFactory->make($command, base_path());
            $process->run();
        } catch (Throwable $throwable) {
            return new DiagnosticCheckData(
                status: 'amber',
                label: $label,
                detail: $throwable->getMessage(),
                remediation: $remediation,
            );
        }

        $successful = $process->isSuccessful();
        $detail = trim($process->getOutput());

        if (! $successful) {
            $errorOutput = trim($process->getErrorOutput());
            $detail = $errorOutput !== '' ? $errorOutput : sprintf('%s is not available.', implode(' ', $command));
        }

        return new DiagnosticCheckData(
            status: $successful ? 'green' : 'amber',
            label: $label,
            detail: $detail,
            remediation: $successful ? null : $remediation,
        );
    }

    private function latestProfileGenerationCheck(): DiagnosticCheckData
    {
        $paths = [
            'storage/app/capell/frontend-optimizer' => storage_path('app/capell/frontend-optimizer'),
            'public/frontend-optimizer' => public_path('frontend-optimizer'),
        ];

        $latestTimestamp = null;
        $latestPath = null;

        foreach ($paths as $relativeDirectory => $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $timestamp = $file->getMTime();

                if ($timestamp === false) {
                    continue;
                }

                if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                    $latestTimestamp = $timestamp;
                    // Production reports remain useful without exposing the host's home directory.
                    // Local debug sessions retain the absolute path for troubleshooting.
                    $latestPath = config('app.debug')
                        ? $file->getPathname()
                        : $relativeDirectory . '/' . $file->getRelativePathname();
                }
            }
        }

        if ($latestTimestamp === null) {
            return new DiagnosticCheckData(
                status: 'amber',
                label: (string) __('capell-admin::diagnostics.latest_optimizer_profile'),
                detail: (string) __('capell-admin::diagnostics.no_optimizer_profile'),
                remediation: (string) __('capell-admin::diagnostics.no_optimizer_profile_remediation'),
            );
        }

        return new DiagnosticCheckData(
            status: 'green',
            label: (string) __('capell-admin::diagnostics.latest_optimizer_profile'),
            detail: (string) __('capell-admin::diagnostics.latest_optimizer_artifact_detail'),
            path: $latestPath,
            generatedAt: CarbonImmutable::createFromTimestamp($latestTimestamp)->toDateTimeString(),
        );
    }

    private function pendingCriticalCssJobsCheck(): DiagnosticCheckData
    {
        $count = $this->pendingCriticalCssJobsQuery()?->count();

        return new DiagnosticCheckData(
            status: $count === null ? 'not_configured' : ($count > 0 ? 'amber' : 'green'),
            label: (string) __('capell-admin::diagnostics.pending_critical_css_jobs'),
            detail: $count === null ? (string) __('capell-admin::diagnostics.optimizer_queue_unavailable') : (string) __('capell-admin::diagnostics.optimizer_queue_pending', ['count' => $count]),
            remediation: $count > 0 ? (string) __('capell-admin::diagnostics.optimizer_worker_context') : null,
        );
    }

    private function pendingCriticalCssJobsQuery(): ?QueryBuilder
    {
        $connection = DB::connection(config('queue.connections.database.connection'));
        $table = config('queue.connections.database.table', 'jobs');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        return $connection->table($table)->where(function (QueryBuilder $query): void {
            $query->where('payload', 'like', '%CriticalCss%')->orWhere('payload', 'like', '%frontend-optimizer%');
        });
    }

    private function failedCriticalCssJobsCheck(): DiagnosticCheckData
    {
        $table = config('queue.failed.table', 'failed_jobs');

        if (! Schema::hasTable($table)) {
            return new DiagnosticCheckData(
                status: 'amber',
                label: (string) __('capell-admin::diagnostics.failed_critical_css_jobs'),
                detail: (string) __('capell-admin::diagnostics.table_unavailable', ['table' => $table]),
                remediation: (string) __('capell-admin::diagnostics.table_unavailable_optimizer_remediation'),
            );
        }

        $failedCount = FailedJob::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('payload', 'like', '%CriticalCss%')
                    ->orWhere('payload', 'like', '%frontend-optimizer%')
                    ->orWhere('exception', 'like', '%CriticalCss%')
                    ->orWhere('exception', 'like', '%critical CSS%')
                    ->orWhere('exception', 'like', '%frontend-optimizer%');
            })
            ->count();

        return new DiagnosticCheckData(
            status: $failedCount > 0 ? 'red' : 'green',
            label: (string) __('capell-admin::diagnostics.failed_critical_css_jobs'),
            detail: $failedCount > 0
                ? (string) __('capell-admin::diagnostics.critical_css_failed_detail', ['count' => $failedCount])
                : (string) __('capell-admin::diagnostics.critical_css_no_failures'),
            remediation: $failedCount > 0 ? (string) __('capell-admin::diagnostics.critical_css_failed_remediation') : null,
        );
    }
}
