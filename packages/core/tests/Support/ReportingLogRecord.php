<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Monolog\Level;

final readonly class ReportingLogRecord
{
    public function __construct(public Level $level, public string $message) {}
}
