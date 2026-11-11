<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\StepProgressReporter;
use Capell\Core\Support\Install\Cli\InstallProgressBar;
use Illuminate\Console\Command;
use Override;

/**
 * Shows the install as one progress bar (or one plain line per step when the terminal cannot draw
 * a bar) and keeps the detailed log back. If a step fails, the last lines of that log are printed
 * with the error so the cause is still visible without rerunning with -v.
 */
final class ConciseConsoleProgressReporter implements StepProgressReporter
{
    private const int FAILURE_TAIL_LINES = 10;

    private ?InstallProgressBar $bar = null;

    private bool $failed = false;

    /** @var list<string> */
    private array $recent = [];

    /** @var list<string> Errors raised while the bar is drawn; printing them would be erased by the next redraw. */
    private array $pendingErrors = [];

    public function __construct(
        private readonly Command $command,
        private readonly bool $useProgressBar,
    ) {}

    #[Override]
    public function advance(int $position, int $total, string $label): void
    {
        if (! $this->useProgressBar) {
            $this->command->line(__('capell-core::install.progress.step', ['position' => $position, 'total' => $total, 'label' => $label]));

            return;
        }

        $this->bar ??= new InstallProgressBar((string) __('capell-core::install.progress.label'), $total, $this->command->getOutput());
        $this->bar->moveTo($position - 1, $total, $label);
    }

    #[Override]
    public function finish(): void
    {
        $this->bar?->complete();
        $this->bar = null;
        $this->flushPendingErrors();
    }

    #[Override]
    public function abandon(): void
    {
        $this->bar?->fail();
        $this->bar = null;
        $this->flushPendingErrors();

        if ($this->failed) {
            return;
        }

        $this->failed = true;

        if ($this->recent !== []) {
            $this->command->newLine();
            $this->command->line(__('capell-core::install.progress.last_output'));

            foreach ($this->recent as $recent) {
                $this->command->line('  ' . $recent);
            }
        }

        $this->recent = [];
    }

    /** Step titles outside the numbered install loop add nothing to the bar or the plain step lines. */
    #[Override]
    public function step(string $label): void {}

    #[Override]
    public function report(string $line): void
    {
        if ($this->failed) {
            $this->command->line($line);

            return;
        }

        $this->recent[] = $line;
        $this->recent = array_slice($this->recent, -self::FAILURE_TAIL_LINES);
    }

    #[Override]
    public function error(string $line): void
    {
        if ($this->bar instanceof InstallProgressBar) {
            $this->pendingErrors[] = $line;

            return;
        }

        $this->command->error($line);
    }

    private function flushPendingErrors(): void
    {
        $pending = $this->pendingErrors;
        $this->pendingErrors = [];

        foreach ($pending as $line) {
            $this->command->error($line);
        }
    }
}
