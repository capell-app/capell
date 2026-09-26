<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\RedactedSignalData;
use RuntimeException;

final readonly class LogChannelReporter implements Reporter
{
    public function __construct(private ReportingTransportBoundary $transports, private ?string $channel = null) {}

    public function report(RedactedSignalData $signal): void
    {
        throw_unless($this->transports->log($signal, $this->channel)->accepted, RuntimeException::class, 'Reporting log transport is unavailable.');
    }
}
