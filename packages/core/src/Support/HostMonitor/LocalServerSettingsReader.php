<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Actions\HostMonitor\HostLoadTrendAction;
use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Contracts\Health\ServerSettingsReader;
use Capell\Core\Data\Diagnostics\HostLoadTrendData;
use Capell\Core\Data\Diagnostics\ServerSettingsData;
use Override;

/**
 * Reads opcache from this PHP process and PHP-FPM, queue worker and memory
 * figures from Linux where they are readable. Anything unreadable stays null.
 * Only setting names and numbers are read: never a value from a pool file other
 * than pm.max_children, and never an environment or command line argument.
 */
final readonly class LocalServerSettingsReader implements ServerSettingsReader
{
    public function __construct(
        private HostMetricsSource $host,
        private HostLoadTrendAction $trends,
    ) {}

    #[Override]
    public function read(): ServerSettingsData
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
            queuePeakDepth: $week instanceof HostLoadTrendData ? $week->peakQueueDepth : null,
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
        $files = [
            ...(@glob('/etc/php/*/fpm/pool.d/*.conf') ?: []),
            ...(@glob('/etc/php-fpm.d/*.conf') ?: []),
            ...(@glob('/usr/local/etc/php-fpm.d/*.conf') ?: []),
        ];

        $largest = null;

        foreach ($files as $file) {
            $contents = @file_get_contents($file);

            if (is_string($contents) && preg_match('/^\s*pm\.max_children\s*=\s*(\d+)/m', $contents, $match) === 1) {
                $largest = max($largest ?? 0, (int) $match[1]);
            }
        }

        return $largest;
    }

    /** @return array{fpm_rss: int|null, queue_workers: int|null} */
    private function processes(): array
    {
        $directories = @glob('/proc/[0-9]*', GLOB_ONLYDIR);

        if ($directories === false || $directories === []) {
            return ['fpm_rss' => null, 'queue_workers' => null];
        }

        $rss = [];
        $workers = 0;

        foreach ($directories as $directory) {
            $cmdline = @file_get_contents($directory . '/cmdline');
            if (! is_string($cmdline)) {
                continue;
            }

            if ($cmdline === '') {
                continue;
            }

            if (str_contains($cmdline, 'queue:work')) {
                $workers++;
            }

            if (str_starts_with(basename(explode("\0", $cmdline)[0]), 'php-fpm') && str_contains($cmdline, 'pool')) {
                $status = @file_get_contents($directory . '/status');

                if (is_string($status) && preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $match) === 1) {
                    $rss[] = (int) $match[1] * 1024;
                }
            }
        }

        sort($rss);

        return [
            'fpm_rss' => $rss === [] ? null : $rss[(int) floor((count($rss) - 1) * 0.9)],
            'queue_workers' => $workers,
        ];
    }
}
