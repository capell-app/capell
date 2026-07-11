<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Diagnostics;

use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildProductionEnvironmentDiagnosticsAction
{
    use AsAction;

    /**
     * @return list<DiagnosticCheckData>
     */
    public function handle(): array
    {
        return [
            $this->appUrlCheck(),
            $this->appKeyCheck(),
            $this->appEnvironmentCheck(),
            $this->debugModeCheck(),
            $this->cacheStoreCheck(),
            $this->databaseCacheTableCheck(),
            $this->queueConnectionCheck(),
            $this->queueJobsTableCheck(),
            $this->schedulerCheck(),
            $this->failedJobsTableCheck(),
            $this->sessionDriverCheck(),
            $this->trustedProxiesCheck(),
            $this->writablePathCheck((string) __('capell-admin::diagnostics.storage_path'), storage_path()),
            $this->writablePathCheck((string) __('capell-admin::diagnostics.bootstrap_cache_path'), base_path('bootstrap/cache')),
        ];
    }

    private function appUrlCheck(): DiagnosticCheckData
    {
        $configuredAppUrl = config('app.url', '');
        $appUrl = is_string($configuredAppUrl) ? $configuredAppUrl : '';

        if ($appUrl === '' || ! str_starts_with($appUrl, 'http')) {
            return new DiagnosticCheckData(
                status: 'red',
                label: (string) __('capell-admin::diagnostics.app_url'),
                detail: (string) __('capell-admin::diagnostics.app_url_invalid'),
                remediation: (string) __('capell-admin::diagnostics.app_url_remediation'),
            );
        }

        if (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            return new DiagnosticCheckData(
                status: 'amber',
                label: (string) __('capell-admin::diagnostics.app_url'),
                detail: (string) __('capell-admin::diagnostics.app_url_detail', ['url' => $appUrl]),
                remediation: (string) __('capell-admin::diagnostics.app_url_local_remediation'),
            );
        }

        return new DiagnosticCheckData(
            status: 'green',
            label: (string) __('capell-admin::diagnostics.app_url'),
            detail: (string) __('capell-admin::diagnostics.app_url_detail', ['url' => $appUrl]),
        );
    }

    private function appKeyCheck(): DiagnosticCheckData
    {
        $key = config('app.key');
        $configured = is_string($key) && trim($key) !== '';

        return new DiagnosticCheckData(
            status: $configured ? 'green' : 'red',
            label: (string) __('capell-admin::diagnostics.app_key'),
            detail: $configured
                ? (string) __('capell-admin::diagnostics.app_key_configured')
                : (string) __('capell-admin::diagnostics.app_key_missing'),
            remediation: $configured ? null : (string) __('capell-admin::diagnostics.app_key_remediation'),
        );
    }

    private function appEnvironmentCheck(): DiagnosticCheckData
    {
        $environment = $this->stringConfig('app.env', 'production');
        $productionLike = in_array($environment, ['production', 'prod'], true);

        return new DiagnosticCheckData(
            status: $productionLike ? 'green' : 'amber',
            label: (string) __('capell-admin::diagnostics.app_environment'),
            detail: (string) __('capell-admin::diagnostics.app_environment_detail', ['environment' => $environment]),
            remediation: $productionLike ? null : (string) __('capell-admin::diagnostics.app_environment_remediation'),
        );
    }

    private function debugModeCheck(): DiagnosticCheckData
    {
        $debug = $this->booleanConfig('app.debug', false);

        return new DiagnosticCheckData(
            status: $debug ? 'red' : 'green',
            label: (string) __('capell-admin::diagnostics.debug_mode'),
            detail: $debug
                ? (string) __('capell-admin::diagnostics.debug_mode_enabled')
                : (string) __('capell-admin::diagnostics.debug_mode_disabled'),
            remediation: $debug ? (string) __('capell-admin::diagnostics.debug_mode_remediation') : null,
        );
    }

    private function cacheStoreCheck(): DiagnosticCheckData
    {
        $store = $this->stringConfig('cache.default', 'unknown');

        return new DiagnosticCheckData(
            status: $store === 'array' ? 'amber' : 'green',
            label: (string) __('capell-admin::diagnostics.cache_store'),
            detail: (string) __('capell-admin::diagnostics.cache_store_detail', ['store' => $store]),
            remediation: $store === 'array' ? (string) __('capell-admin::diagnostics.cache_store_remediation') : null,
        );
    }

    private function databaseCacheTableCheck(): DiagnosticCheckData
    {
        $store = $this->stringConfig('cache.default', 'unknown');
        $table = $this->stringConfig('cache.stores.database.table', 'cache');

        if ($store !== 'database') {
            return new DiagnosticCheckData(
                status: 'green',
                label: (string) __('capell-admin::diagnostics.database_cache_table'),
                detail: (string) __('capell-admin::diagnostics.database_cache_table_not_required', ['store' => $store]),
            );
        }

        $tableExists = resolve(RuntimeSchemaState::class)->hasTable($table);

        return new DiagnosticCheckData(
            status: $tableExists ? 'green' : 'red',
            label: (string) __('capell-admin::diagnostics.database_cache_table'),
            detail: $tableExists
                ? (string) __('capell-admin::diagnostics.database_cache_table_exists', ['table' => $table])
                : (string) __('capell-admin::diagnostics.database_cache_table_missing', ['table' => $table]),
            remediation: $tableExists ? null : (string) __('capell-admin::diagnostics.database_cache_table_remediation'),
        );
    }

    private function queueConnectionCheck(): DiagnosticCheckData
    {
        $connection = $this->stringConfig('queue.default', 'sync');

        return new DiagnosticCheckData(
            status: $connection === 'sync' ? 'amber' : 'green',
            label: (string) __('capell-admin::diagnostics.queue_connection'),
            detail: (string) __('capell-admin::diagnostics.queue_connection_detail', ['connection' => $connection]),
            remediation: $connection === 'sync' ? (string) __('capell-admin::diagnostics.queue_connection_remediation') : null,
        );
    }

    private function queueJobsTableCheck(): DiagnosticCheckData
    {
        $connection = $this->stringConfig('queue.default', 'sync');
        $table = $this->stringConfig('queue.connections.database.table', 'jobs');

        if ($connection !== 'database') {
            return new DiagnosticCheckData(
                status: 'green',
                label: (string) __('capell-admin::diagnostics.queue_jobs_table'),
                detail: (string) __('capell-admin::diagnostics.queue_jobs_table_not_required', ['connection' => $connection]),
            );
        }

        $tableExists = resolve(RuntimeSchemaState::class)->hasTable($table);

        return new DiagnosticCheckData(
            status: $tableExists ? 'green' : 'red',
            label: (string) __('capell-admin::diagnostics.queue_jobs_table'),
            detail: $tableExists
                ? (string) __('capell-admin::diagnostics.queue_jobs_table_exists', ['table' => $table])
                : (string) __('capell-admin::diagnostics.queue_jobs_table_missing', ['table' => $table]),
            remediation: $tableExists ? null : (string) __('capell-admin::diagnostics.queue_jobs_table_remediation'),
        );
    }

    private function schedulerCheck(): DiagnosticCheckData
    {
        return new DiagnosticCheckData(
            status: 'amber',
            label: (string) __('capell-admin::diagnostics.scheduler'),
            detail: (string) __('capell-admin::diagnostics.scheduler_detail'),
            remediation: (string) __('capell-admin::diagnostics.scheduler_remediation'),
        );
    }

    private function failedJobsTableCheck(): DiagnosticCheckData
    {
        $table = $this->stringConfig('queue.failed.table', 'failed_jobs');
        $tableExists = resolve(RuntimeSchemaState::class)->hasTable($table);

        return new DiagnosticCheckData(
            status: $tableExists ? 'green' : 'amber',
            label: (string) __('capell-admin::diagnostics.failed_jobs_table'),
            detail: $tableExists
                ? (string) __('capell-admin::diagnostics.failed_jobs_table_exists', ['table' => $table])
                : (string) __('capell-admin::diagnostics.failed_jobs_table_missing', ['table' => $table]),
            remediation: $tableExists ? null : (string) __('capell-admin::diagnostics.failed_jobs_table_remediation'),
        );
    }

    private function sessionDriverCheck(): DiagnosticCheckData
    {
        $driver = $this->stringConfig('session.driver', 'file');

        return new DiagnosticCheckData(
            status: $driver === 'array' ? 'amber' : 'green',
            label: (string) __('capell-admin::diagnostics.session_driver'),
            detail: (string) __('capell-admin::diagnostics.session_driver_detail', ['driver' => $driver]),
            remediation: $driver === 'array' ? (string) __('capell-admin::diagnostics.session_driver_remediation') : null,
        );
    }

    private function trustedProxiesCheck(): DiagnosticCheckData
    {
        $trustedProxies = config('trustedproxy.proxies', config('app.trusted_proxies'));
        $configured = is_array($trustedProxies)
            ? $trustedProxies !== []
            : is_string($trustedProxies) && $trustedProxies !== '';

        return new DiagnosticCheckData(
            status: $configured ? 'green' : 'amber',
            label: (string) __('capell-admin::diagnostics.trusted_proxies'),
            detail: $configured
                ? (string) __('capell-admin::diagnostics.trusted_proxies_configured')
                : (string) __('capell-admin::diagnostics.trusted_proxies_missing'),
            remediation: $configured ? null : (string) __('capell-admin::diagnostics.trusted_proxies_remediation'),
        );
    }

    private function writablePathCheck(string $label, string $path): DiagnosticCheckData
    {
        return new DiagnosticCheckData(
            status: is_dir($path) && is_writable($path) ? 'green' : 'red',
            label: $label,
            detail: is_dir($path) && is_writable($path)
                ? (string) __('capell-admin::diagnostics.path_writable', ['path' => $path])
                : (string) __('capell-admin::diagnostics.path_not_writable', ['path' => $path]),
            remediation: is_dir($path) && is_writable($path) ? null : (string) __('capell-admin::diagnostics.path_writable_remediation'),
            path: $path,
        );
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    private function booleanConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }
}
