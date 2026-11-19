<?php

declare(strict_types=1);

namespace Capell\Core\Support\Scheduling;

use Capell\Core\Exceptions\ScheduledCommandFailedException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;

/**
 * Runs an Artisan command inside the `schedule:run` process instead of starting a new
 * `php artisan` process that boots the whole application again.
 *
 * Every per-minute command that starts its own process costs a full boot each minute, which
 * is most of a small host's scheduler load. A host application opts in with
 * `capell.schedule.run_frequent_commands_in_process`; otherwise the command is scheduled as
 * usual. A non-zero exit becomes an exception, so the scheduler fails the event, runs its
 * failure callbacks and reports it, as it does for a separate process. A transaction the
 * command leaves open is rolled back and scoped instances are forgotten, so one task cannot
 * leak state into the next task in the same minute.
 */
final readonly class RunScheduledCommandInProcess
{
    public function __construct(public string $command) {}

    public function __invoke(Kernel $artisan, DatabaseManager $database, Container $container): void
    {
        $connection = $database->connection();
        $transactionLevel = $connection->transactionLevel();

        try {
            $exitCode = $artisan->call($this->command, ['--no-interaction' => true]);
        } finally {
            while ($connection->transactionLevel() > $transactionLevel) {
                $connection->rollBack();
            }

            $container->forgetScopedInstances();
        }

        if ($exitCode !== 0) {
            throw ScheduledCommandFailedException::forExitCode($this->command, $exitCode);
        }
    }

    /**
     * Schedule a command that takes no arguments. The event keeps the command's name, so
     * `withoutOverlapping()`, `onOneServer()` and `schedule:list` behave as they would for
     * `$schedule->command()`.
     */
    public static function schedule(Schedule $schedule, string $command): Event
    {
        if (config('capell.schedule.run_frequent_commands_in_process', false) !== true) {
            return $schedule->command($command);
        }

        return $schedule->call(new self($command))->name($command);
    }
}
