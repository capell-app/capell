<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

final class FileLogProgressReporter implements ProgressReporter
{
    private readonly string $logPath;

    public function __construct(
        private readonly string $installId,
        private readonly CacheProgressReporter $inner,
    ) {
        $this->logPath = storage_path(sprintf('logs/capell-install-%s.log', $this->installId));
    }

    public function step(string $label): void
    {
        $this->inner->step($label);
        $this->writeLine('STEP', $label);
    }

    public function report(string $line): void
    {
        $this->inner->report($line);
        $this->writeLine('INFO', $line);
    }

    public function error(string $line): void
    {
        $this->inner->error($line);
        $this->writeLine('ERROR', $line);
    }

    public function markRunning(): void
    {
        $this->inner->markRunning();
        $this->writeLine('STATUS', 'running');
    }

    public function markComplete(): void
    {
        $this->inner->markComplete();
        $this->writeLine('STATUS', 'complete');
    }

    public function markFailed(): void
    {
        $this->inner->markFailed();
        $this->writeLine('STATUS', 'failed');
    }

    public function logPath(): string
    {
        return $this->logPath;
    }

    private function writeLine(string $type, string $line): void
    {
        $directory = dirname($this->logPath);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        $entry = sprintf('[%s] [%s] %s%s', Date::now()->toIso8601String(), $type, $line, PHP_EOL);
        File::append($this->logPath, $entry);
    }
}
