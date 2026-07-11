<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Contracts\ProgressReporter;

final class InstallSupportActionReporter implements ProgressReporter
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    public array $lines = [];

    public function step(string $label): void
    {
        $this->lines[] = ['step', $label];
    }

    public function report(string $line): void
    {
        $this->lines[] = ['report', $line];
    }

    public function error(string $line): void
    {
        $this->lines[] = ['error', $line];
    }
}
