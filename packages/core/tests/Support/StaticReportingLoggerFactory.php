<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class StaticReportingLoggerFactory
{
    public static ?LogManager $logs = null;

    public function __invoke(): LoggerInterface
    {
        return self::$logs?->channel('boundary-broken') ?? throw new RuntimeException('Static logger is unavailable.');
    }
}
