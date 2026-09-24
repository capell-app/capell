<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\SignalData;
use Illuminate\Log\LogManager;

final readonly class LogChannelReporter implements Reporter
{
    public function __construct(private LogManager $logs, private ?string $channel = null) {}

    public function report(SignalData $signal): void
    {
        new ReportingLogManager($this->logs)->channel($this->channel)->log($signal->severity->value, $signal->toJson());
    }
}
