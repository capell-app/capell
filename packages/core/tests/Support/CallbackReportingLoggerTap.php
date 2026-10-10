<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Log\LogManager;

final class CallbackReportingLoggerTap
{
    public function __construct(private readonly LogManager $logs) {}

    public function __invoke(): void
    {
        $this->logs->channel('boundary-broken');
    }
}
