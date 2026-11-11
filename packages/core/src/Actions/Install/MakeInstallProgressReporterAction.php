<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Install\ConciseConsoleProgressReporter;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class MakeInstallProgressReporterAction
{
    use AsFake;
    use AsObject;

    /**
     * -v keeps the detailed log. Otherwise the install shows a progress bar when a person is at a
     * terminal that can draw one, and plain step lines everywhere else (CI, pipes, --no-interaction).
     */
    public function handle(Command $command, bool $interactive): ProgressReporter
    {
        $output = $command->getOutput();

        if ($output->isVerbose()) {
            return new ConsoleProgressReporter($command);
        }

        $drawsBar = $output->isDecorated()
            && $interactive
            && defined('STDOUT')
            && stream_isatty(STDOUT);

        return new ConciseConsoleProgressReporter($command, $drawsBar);
    }
}
