<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

final class FacadeReportingLoggerFactory
{
    public function __invoke(): LoggerInterface
    {
        return Log::channel('boundary-broken');
    }
}
