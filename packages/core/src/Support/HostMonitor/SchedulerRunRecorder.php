<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Octane\Resettable;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Measures what one `schedule:run` costs. Each task that runs a command starts
 * its own PHP process and boots the whole application, so many small tasks can
 * use more CPU than any single one of them shows. The run's CPU time is the
 * time spent by this process and by the children it waited for.
 *
 * Tasks started with runInBackground() are not waited for, so their CPU time is
 * not counted here.
 */
final class SchedulerRunRecorder implements Resettable
{
    private const int KEEP_SLOWEST = 3;

    /** @var array{minute: string, started: float, self: float, children: float, tasks: int, processes: int, slowest: list<array{command: string, seconds: float}>}|null */
    private ?array $run = null;

    public function __construct(private readonly HostMonitorStore $store) {}

    public function flushOctaneState(): void
    {
        $this->run = null;
    }

    public function starting(): void
    {
        if (config('capell.health.host.enabled', true) !== true) {
            return;
        }

        if ($this->run !== null && microtime(true) - $this->run['started'] > 600) {
            $this->run = null;
        }

        $this->run ??= [
            'minute' => now()->format('YmdHi'),
            'started' => microtime(true),
            'self' => $this->cpu(0),
            'children' => $this->cpu(1),
            'tasks' => 0,
            'processes' => 0,
            'slowest' => [],
        ];
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->record($event->task, $event->runtime);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, 0.0);
    }

    /**
     * The most recent run that began before the current minute, so a run that is
     * still going is never mistaken for a finished one.
     *
     * @return array{minute: string, tasks: int, processes: int, cpu_seconds: float, wall_seconds: float, slowest: list<array{command: string, seconds: float}>}|null
     */
    public function lastCompletedRun(string $node): ?array
    {
        return $this->store->schedulerRun($node, now()->subMinute()->format('YmdHi'));
    }

    public function reset(): void
    {
        $this->run = null;
    }

    private function record(Event $task, float $runtime): void
    {
        if ($this->run === null) {
            return;
        }

        $this->run['tasks']++;

        if (! $task instanceof CallbackEvent) {
            $this->run['processes']++;
        }

        $this->run['slowest'] = array_slice(collect([
            ...$this->run['slowest'],
            ['command' => $this->describe($task), 'seconds' => round($runtime, 2)],
        ])->sortByDesc('seconds')->values()->all(), 0, self::KEEP_SLOWEST);

        $this->store->putSchedulerRun($this->store->node(), $this->run['minute'], [
            'tasks' => $this->run['tasks'],
            'processes' => $this->run['processes'],
            'cpu_seconds' => round(max(0.0, $this->cpu(0) - $this->run['self']) + max(0.0, $this->cpu(1) - $this->run['children']), 2),
            'wall_seconds' => round(microtime(true) - $this->run['started'], 2),
            'slowest' => $this->run['slowest'],
        ]);
    }

    private function describe(Event $task): string
    {
        $summary = $task->description;

        if (! is_string($summary) || $summary === '') {
            $command = is_string($task->command) ? $task->command : '';
            $summary = preg_match("/artisan'?\s+(.+)\z/", $command, $match) === 1 ? $match[1] : $command;
        }

        // Only the command name: arguments can hold values that should not leave the server.
        $name = explode(' ', trim($summary, "' "))[0];

        return $name !== '' ? $name : 'task';
    }

    /** User plus system CPU seconds. $who is 0 for this process, 1 for the children it has waited for. */
    private function cpu(int $who): float
    {
        $usage = getrusage($who);

        if ($usage === false) {
            return 0.0;
        }

        return (float) $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000
            + (float) $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000;
    }
}
