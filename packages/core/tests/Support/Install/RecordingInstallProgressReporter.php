<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Install;

use Capell\Core\Contracts\ProgressReporter;

final class RecordingInstallProgressReporter implements ProgressReporter
{
    /**
     * @var array<int, string>
     */
    public array $lines = [];

    public function step(string $label): void
    {
        $this->lines[] = $label;
    }

    public function report(string $line): void
    {
        $this->lines[] = $line;
    }

    public function error(string $line): void
    {
        $this->lines[] = $line;
    }
}
