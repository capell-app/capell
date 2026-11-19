<?php

declare(strict_types=1);

use Capell\Core\Exceptions\ScheduledCommandFailedException;
use Capell\Core\Support\Scheduling\RunScheduledCommandInProcess;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

function registerScheduledTestCommand(string $name, int $exitCode, bool $leaveTransactionOpen = false): void
{
    $command = new class($name, $exitCode, $leaveTransactionOpen) extends Command
    {
        public static int $runs = 0;

        public function __construct(string $name, private readonly int $exitCode, private readonly bool $leaveTransactionOpen)
        {
            $this->signature = $name;
            parent::__construct();
        }

        public function handle(): int
        {
            self::$runs++;

            if ($this->leaveTransactionOpen) {
                DB::beginTransaction();
            }

            return $this->exitCode;
        }
    };

    resolve(Kernel::class)->registerCommand($command);
}

it('starts a separate process for the command unless the host opts in', function (): void {
    config()->set('capell.schedule.run_frequent_commands_in_process', false);

    $event = RunScheduledCommandInProcess::schedule(new Schedule, 'testing:scheduled');

    expect($event)->not->toBeInstanceOf(CallbackEvent::class)
        ->and($event->command)->toContain('testing:scheduled');
});

it('runs the command inside the scheduler process when the host opts in', function (): void {
    config()->set('capell.schedule.run_frequent_commands_in_process', true);
    registerScheduledTestCommand('testing:scheduled-ok', 0);

    $event = RunScheduledCommandInProcess::schedule(new Schedule, 'testing:scheduled-ok')
        ->everyMinute()
        ->withoutOverlapping(5)
        ->onOneServer();
    $event->run(app());

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->description)->toBe('testing:scheduled-ok')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->exitCode)->toBe(0);
});

it('fails the event and runs its failure handler when the command exits non-zero', function (): void {
    config()->set('capell.schedule.run_frequent_commands_in_process', true);
    registerScheduledTestCommand('testing:scheduled-failing', 1);
    $failureHandled = false;

    $event = RunScheduledCommandInProcess::schedule(new Schedule, 'testing:scheduled-failing')
        ->onFailure(static function () use (&$failureHandled): void {
            $failureHandled = true;
        });

    expect(static function () use ($event): void {
        $event->run(app());
    })->toThrow(ScheduledCommandFailedException::class, 'testing:scheduled-failing')
        ->and($event->exitCode)->toBe(1)
        ->and($failureHandled)->toBeTrue();
});

it('rolls back a transaction the command leaves open so the next task starts clean', function (): void {
    registerScheduledTestCommand('testing:scheduled-leaky', 0, leaveTransactionOpen: true);
    $level = DB::transactionLevel();

    (new RunScheduledCommandInProcess('testing:scheduled-leaky'))(resolve(Kernel::class), resolve(ConnectionResolverInterface::class), app());

    expect(DB::transactionLevel())->toBe($level);
});
