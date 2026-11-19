<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads load, memory and per-process CPU for the local server. Linux reports
 * everything; other platforms report load and core count and leave the rest
 * empty, which the assessment treats as "no problem seen" rather than a failure.
 */
final readonly class LocalHostMetricsSource implements HostMetricsSource
{
    /** Linux counts process CPU in hundredths of a second. */
    private const int CLOCK_TICKS_PER_SECOND = 100;

    private const int TOP_PROCESS_COUNT = 5;

    public function __construct(
        private HostMonitorStore $store,
        private SchedulerRunRecorder $scheduler,
    ) {}

    public function read(): HostSampleData
    {
        $node = $this->store->node();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        [$load1, $load5, $load15] = is_array($load) ? $load : [0.0, 0.0, 0.0];
        $memory = $this->memory();
        $scheduler = $this->scheduler->lastCompletedRun($node);

        return new HostSampleData(
            takenAt: now()->getTimestamp(),
            node: $node,
            cores: $this->cores(),
            load1: $load1,
            load5: $load5,
            load15: $load15,
            memoryTotalBytes: $memory['memory_total'],
            memoryAvailableBytes: $memory['memory_available'],
            swapTotalBytes: $memory['swap_total'],
            swapUsedBytes: $memory['swap_used'],
            topProcesses: $this->busiestProcesses($node),
            schedulerCpuSeconds: $scheduler['cpu_seconds'] ?? null,
            schedulerWallSeconds: $scheduler['wall_seconds'] ?? null,
            schedulerTasks: $scheduler['tasks'] ?? 0,
            schedulerProcesses: $scheduler['processes'] ?? 0,
            slowestSchedulerTasks: $scheduler['slowest'] ?? [],
            queueDepth: $this->queueDepth(),
        );
    }

    /** Waiting jobs on the default queue: one cheap size read, and null where the driver cannot answer it. */
    private function queueDepth(): ?int
    {
        if (config('capell.health.host.history.enabled', true) !== true) {
            return null;
        }

        $default = config('queue.default');

        if (! is_string($default) || in_array($default, ['sync', 'null', 'deferred', 'background'], true)) {
            return null;
        }

        try {
            return max(0, Queue::size());
        } catch (Throwable) {
            return null;
        }
    }

    private function cores(): int
    {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');

        if (is_string($cpuinfo)) {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

            if ($count !== false && $count > 0) {
                return $count;
            }
        }

        foreach ([['nproc'], ['sysctl', '-n', 'hw.ncpu']] as $command) {
            $output = $this->run($command);

            if ($output !== null && ctype_digit($output) && (int) $output > 0) {
                return (int) $output;
            }
        }

        return 1;
    }

    /** @return array{memory_total: int|null, memory_available: int|null, swap_total: int|null, swap_used: int|null} */
    private function memory(): array
    {
        $empty = ['memory_total' => null, 'memory_available' => null, 'swap_total' => null, 'swap_used' => null];
        $meminfo = @file_get_contents('/proc/meminfo');

        if (! is_string($meminfo)) {
            return $empty;
        }

        $values = [];

        foreach (['MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree'] as $key) {
            if (preg_match('/^' . $key . ':\s+(\d+)\s+kB/m', $meminfo, $match) === 1) {
                $values[$key] = (int) $match[1] * 1024;
            }
        }

        if (! isset($values['MemTotal'], $values['MemAvailable'])) {
            return $empty;
        }

        $swapTotal = $values['SwapTotal'] ?? null;

        return [
            'memory_total' => $values['MemTotal'],
            'memory_available' => $values['MemAvailable'],
            'swap_total' => $swapTotal,
            'swap_used' => $swapTotal !== null && isset($values['SwapFree']) ? max(0, $swapTotal - $values['SwapFree']) : null,
        ];
    }

    /**
     * The processes that used the most CPU since the previous sample. Comparing
     * two readings of each process's CPU time also counts short-lived processes,
     * which a point-in-time process list would miss entirely.
     *
     * @return list<array{pid: int, user: string, cpu: float, command: string}>
     */
    private function busiestProcesses(string $node): array
    {
        $directories = @glob('/proc/[0-9]*', GLOB_ONLYDIR);

        if ($directories === false || $directories === []) {
            return [];
        }

        $now = microtime(true);
        $current = [];

        foreach ($directories as $directory) {
            $stat = @file_get_contents($directory . '/stat');

            if (! is_string($stat)) {
                continue;
            }

            $closing = strrpos($stat, ')');

            if ($closing === false) {
                continue;
            }

            $fields = explode(' ', substr($stat, $closing + 2));

            if (! isset($fields[11], $fields[12])) {
                continue;
            }

            $current[(int) basename($directory)] = (int) $fields[11] + (int) $fields[12];
        }

        $previous = $this->store->cpuSnapshot($node);
        $this->store->putCpuSnapshot($node, ['at' => $now, 'ticks' => $current]);

        $elapsed = $previous === null ? 0.0 : $now - $previous['at'];

        // A gap longer than a few minutes means the monitor was not running; skip rather than average over it.
        if ($previous === null || $elapsed < 5 || $elapsed > 600) {
            return [];
        }

        $usage = [];

        foreach ($current as $pid => $ticks) {
            $delta = $ticks - ($previous['ticks'][$pid] ?? 0);

            if ($delta > 0) {
                $usage[$pid] = $delta / self::CLOCK_TICKS_PER_SECOND / $elapsed * 100;
            }
        }

        arsort($usage);

        $processes = [];

        foreach (array_slice($usage, 0, self::TOP_PROCESS_COUNT, true) as $pid => $cpu) {
            $processes[] = [
                'pid' => $pid,
                'user' => $this->owner('/proc/' . $pid),
                'cpu' => round($cpu, 1),
                'command' => $this->commandName('/proc/' . $pid . '/cmdline'),
            ];
        }

        return $processes;
    }

    private function owner(string $path): string
    {
        $uid = @fileowner($path);

        if ($uid === false) {
            return '';
        }

        $entry = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;

        return is_array($entry) ? $entry['name'] : (string) $uid;
    }

    private function commandName(string $cmdlinePath): string
    {
        $raw = @file_get_contents($cmdlinePath);

        return is_string($raw) && $raw !== '' ? CommandNameDescriber::describe(explode("\0", rtrim($raw, "\0"))) : '';
    }

    /** @param  list<string>  $command */
    private function run(array $command): ?string
    {
        try {
            $process = new Process($command);
            $process->setTimeout(2);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
