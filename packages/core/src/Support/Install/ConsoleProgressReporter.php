<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Console\Command;

final class ConsoleProgressReporter implements ProgressReporter
{
    private bool $hasStarted = false;

    public function __construct(private readonly Command $command) {}

    public function step(string $label): void
    {
        if ($this->hasStarted) {
            $this->command->line('');
        }

        $this->hasStarted = true;

        $this->command->comment($label);
    }

    public function report(string $line): void
    {
        $this->command->line($line);
    }

    public function error(string $line): void
    {
        $this->command->error($line);
    }
}
