<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface ProgressReporter
{
    public function step(string $label): void;

    public function report(string $line): void;

    public function error(string $line): void;
}
