<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

final class GlobalReportingLoggerFactory
{
    public function __invoke(): LoggerInterface
    {
        $applicationResolver = 'app';

        return $applicationResolver(LogManager::class)->channel('boundary-broken');
    }
}
