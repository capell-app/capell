<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Names a scheduled task the way the scheduler identifies it: its description
 * (set with name() or description(), and by Schedule::job()) or, for a command,
 * the artisan command name. Arguments are left out because they can hold
 * values that should not leave the server.
 */
final class ScheduledTaskName
{
    /** The artisan command a command task runs, or null for closures and jobs. */
    public static function artisanCommand(Event $task): ?string
    {
        if ($task instanceof CallbackEvent || ! is_string($task->command)) {
            return null;
        }

        if (preg_match("/artisan'?\s+(.+)\z/", $task->command, $match) !== 1) {
            return null;
        }

        $name = explode(' ', trim($match[1], "' "))[0];

        return $name !== '' ? $name : null;
    }

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
