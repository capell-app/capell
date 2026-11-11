<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Laravel\Prompts\Progress;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Laravel Prompts progress bar, driven by absolute position so it follows an install plan that
 * grows as package steps are discovered, and able to stop in its error state.
 */
final class InstallProgressBar
{
    private const string SHOW_CURSOR = "\e[?25h";

    /** @var Progress<int> */
    private readonly Progress $progress;

    private bool $running = false;

    public function __construct(string $label, int $total, private readonly OutputInterface $output)
    {
        $this->progress = new Progress($label, max($total, 1));
    }

    public function moveTo(int $completed, int $total, string $hint): void
    {
        $this->bindOutput();
        $this->progress->total = max($total, 1);
        $this->progress->progress = min(max($completed, 0), $this->progress->total);
        $this->progress->hint = $hint;

        if (! $this->running) {
            $this->running = true;
            $this->progress->start();

            return;
        }

        $this->progress->advance(0);
    }

    public function complete(): void
    {
        if (! $this->running) {
            return;
        }

        $this->bindOutput();
        $this->running = false;
        $this->progress->progress = $this->progress->total;
        $this->progress->hint = '';
        $this->progress->finish();
    }

    public function fail(): void
    {
        if (! $this->running) {
            return;
        }

        $this->bindOutput();
        $this->running = false;
        $this->progress->state = 'error';
        $this->progress->advance(0);
        // Starting the bar hid the cursor; a failed bar is never finished, so give it back.
        $this->output->write(self::SHOW_CURSOR);

        // Starting the bar also took over Ctrl+C; hand it back so the failure guidance can finish printing.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_DFL);
        }
    }

    /** Nested Artisan calls re-bind Prompts to their own buffered output; draw on the real one. */
    private function bindOutput(): void
    {
        Prompt::setOutput($this->output);
    }
}
