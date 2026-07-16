<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Diagnostics;

use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Models\FailedJob;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
        return [
            $this->commandCheck(
                ['node', '--version'],
                (string) __('capell-admin::diagnostics.node_runtime'),
                (string) __('capell-admin::diagnostics.node_runtime_remediation'),
            ),
            $this->commandCheck(
                ['npx', 'playwright', '--version'],
                (string) __('capell-admin::diagnostics.playwright_runtime'),
                (string) __('capell-admin::diagnostics.playwright_runtime_remediation'),
            ),
            $this->latestProfileGenerationCheck(),
            $this->failedCriticalCssJobsCheck(),
        ];
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
            storage_path('app/capell/frontend-optimizer'),
            public_path('frontend-optimizer'),
        ];

        $latestTimestamp = null;
        $latestPath = null;

        foreach ($paths as $path) {
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
                    $latestPath = $file->getPathname();
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
