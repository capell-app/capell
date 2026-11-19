<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Illuminate\Console\Scheduling\Event;

/**
 * Names a scheduled task the way the scheduler identifies it: its description
 * (set with name() or description(), and by Schedule::job()) or, for a command,
 * the artisan command name. Arguments are left out because they can hold
 * values that should not leave the server.
 */
final class ScheduledTaskName
{
    public static function of(Event $task): string
    {
        $summary = $task->description;

        if (! is_string($summary) || $summary === '') {
            $command = is_string($task->command) ? $task->command : '';
            $summary = preg_match("/artisan'?\s+(.+)\z/", $command, $match) === 1 ? $match[1] : $command;
        }

        $name = explode(' ', trim($summary, "' "))[0];

        return $name !== '' ? $name : 'task';
    }
}
