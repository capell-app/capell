<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CallbackReportingLoggerFactory
{
    public ?LogManager $logs = null;

    public function __invoke(): LoggerInterface
    {
        return $this->logs?->channel('boundary-broken') ?? throw new RuntimeException('Callback logger is unavailable.');
    }
}
