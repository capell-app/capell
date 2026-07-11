<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\ProgressReporter;

final class NullProgressReporter implements ProgressReporter
{
    public function step(string $label): void {}

    public function report(string $line): void {}

    public function error(string $line): void {}
}
