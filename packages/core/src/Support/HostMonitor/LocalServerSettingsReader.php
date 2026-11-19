<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Actions\HostMonitor\HostLoadTrendAction;
use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Contracts\Health\ServerSettingsReader;
use Capell\Core\Data\Diagnostics\HostLoadTrendData;
use Capell\Core\Data\Diagnostics\ServerSettingsData;
use Illuminate\Support\Facades\Cache;
use Override;

/**
 * Reads opcache from this PHP process and PHP-FPM, queue worker and memory
 * figures from Linux where they are readable. Anything unreadable stays null.
 * Only setting names and numbers are read: never a value from a pool file other
 * than pm.max_children, and never an environment or command line argument.
 */
final readonly class LocalServerSettingsReader implements ServerSettingsReader
{
    private const int CACHE_SECONDS = 300;

    public function __construct(
        private HostMetricsSource $host,
        private HostLoadTrendAction $trends,
        private HostMonitorStore $store,
        private string $procRoot = '/proc',
        /** @var list<string> */
        private array $poolPatterns = ['/etc/php/*/fpm/pool.d/*.conf', '/etc/php-fpm.d/*.conf', '/usr/local/etc/php-fpm.d/*.conf'],
    ) {}

    /** Cached for a few minutes per node and SAPI: the scan walks the process table, and opcache differs between CLI and FPM. */
    #[Override]
    public function read(): ServerSettingsData
    {
        /** @var array<string, mixed> $cached */
        $cached = Cache::remember(
            'capell:server-settings:' . $this->store->node() . ':' . PHP_SAPI,
            self::CACHE_SECONDS,
            fn (): array => $this->measure()->toArray(),
        );

        return ServerSettingsData::from($cached);
    }

    private function measure(): ServerSettingsData
    {
        $sample = $this->host->read();
        $week = $this->trends->handle($sample->node, 7);
        $status = $this->opcacheStatus();
        $processes = $this->processes();

        $validate = ini_get('opcache.validate_timestamps');

        return new ServerSettingsData(
            cores: $sample->cores,
            memoryTotalBytes: $sample->memoryTotalBytes,
            swapPeakUsedPercent: $week instanceof HostLoadTrendData ? $week->peakSwapUsedPercent : $sample->swapUsedPercent(),
            production: app()->isProduction(),
            opcacheEnabled: $status instanceof OpcacheStatus,
            validateTimestamps: $status instanceof OpcacheStatus && is_string($validate) ? filter_var($validate, FILTER_VALIDATE_BOOLEAN) : null,
            opcacheMemoryBytes: $status instanceof OpcacheStatus ? $this->megabytes('opcache.memory_consumption') : null,
            opcacheMemoryUsedBytes: $status?->usedMemory,
            internedBufferBytes: $status instanceof OpcacheStatus ? $this->megabytes('opcache.interned_strings_buffer') : null,
            internedUsedBytes: $status?->internedUsed,
            maxAcceleratedFiles: $status instanceof OpcacheStatus ? (int) ini_get('opcache.max_accelerated_files') : null,
            cachedScripts: $status?->cachedScripts,
            fpmMaxChildren: $this->maxChildren(),
            fpmWorkerRssBytes: $processes['fpm_rss'],
            queueWorkers: $processes['queue_workers'],
            queueWorkerRssBytes: $processes['queue_rss'],
            queuePeakDepth: $week instanceof HostLoadTrendData ? $week->peakQueueDepth : null,
            queueHistoryHours: $week instanceof HostLoadTrendData ? $week->hours : null,
        );
    }

    private function megabytes(string $name): ?int
    {
        $value = ini_get($name);

        return is_string($value) && ctype_digit($value) ? (int) $value * 1024 * 1024 : null;
    }

    private function opcacheStatus(): ?OpcacheStatus
    {
        if (! function_exists('opcache_get_status')) {
            return null;
        }

        $status = @opcache_get_status(false);

        if (! is_array($status) || ($status['opcache_enabled'] ?? false) !== true) {
            return null;
        }

        return new OpcacheStatus(
            usedMemory: $this->number($status['memory_usage']['used_memory'] ?? null),
            internedUsed: $this->number($status['interned_strings_usage']['used_memory'] ?? null),
            cachedScripts: $this->number($status['opcache_statistics']['num_cached_scripts'] ?? null),
        );
    }

    private function number(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** The largest pm.max_children set in a readable pool file, or null when none can be read. */
    private function maxChildren(): ?int
    {
        $files = [];

        foreach ($this->poolPatterns as $pattern) {
            array_push($files, ...(@glob($pattern) ?: []));
        }

        $largest = null;

        foreach ($files as $file) {
            $contents = @file_get_contents($file);

            if (is_string($contents) && preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/m', $contents, $match) === 1) {
                $largest = max($largest ?? 0, (int) $match[1]);
            }
        }

        return $largest;
    }

    /** @return array{fpm_rss: int|null, queue_workers: int|null, queue_rss: int|null} */
    private function processes(): array
    {
        $directories = @glob($this->procRoot . '/[0-9]*', GLOB_ONLYDIR);

        if ($directories === false || $directories === []) {
            return ['fpm_rss' => null, 'queue_workers' => null, 'queue_rss' => null];
        }

        $fpm = [];
        $workers = 0;
        $queueMemory = 0;

        foreach ($directories as $directory) {
            $cmdline = @file_get_contents($directory . '/cmdline');

            if (! is_string($cmdline)) {
                continue;
            }

            if ($cmdline === '') {
                continue;
            }

            $argv = explode("\0", rtrim($cmdline, "\0"));

            if (in_array('queue:work', $argv, true)) {
                $workers++;
                $queueMemory += $this->memory($directory) ?? 0;
            } elseif (str_starts_with(basename($argv[0]), 'php-fpm') && str_contains($cmdline, 'pool')) {
                $memory = $this->memory($directory);

                if ($memory !== null) {
                    $fpm[] = $memory;
                }
            }
        }

        sort($fpm);

        return [
            'fpm_rss' => $fpm === [] ? null : $fpm[(int) floor((count($fpm) - 1) * 0.9)],
            'queue_workers' => $workers,
            'queue_rss' => $workers === 0 ? null : $queueMemory,
        ];
    }

    /** Proportional set size where the kernel reports it, which does not count shared opcache memory once per worker; resident size otherwise. */
    private function memory(string $directory): ?int
    {
        $rollup = @file_get_contents($directory . '/smaps_rollup');

        if (is_string($rollup) && preg_match('/^Pss:\s+(\d+)\s+kB/m', $rollup, $match) === 1) {
            return (int) $match[1] * 1024;
        }

        $status = @file_get_contents($directory . '/status');

        return is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $match) === 1 ? (int) $match[1] * 1024 : null;
    }
}
