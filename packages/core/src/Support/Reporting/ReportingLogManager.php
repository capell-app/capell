<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Illuminate\Log\LogManager;
use Override;
use RuntimeException;

final class ReportingLogManager extends LogManager
{
    public function __construct(LogManager $logs)
    {
        parent::__construct($logs->app);

        // A cached stack can already contain an emergency logger from a failed member.
        // Rebuild from configuration so every member uses the protected resolution path.
        $this->sharedContext = $logs->sharedContext;

        foreach ($logs->customCreators as $driver => $creator) {
            $this->extend($driver, $creator);
        }
    }

    #[Override]
    protected function createEmergencyLogger(): never
    {
        // Laravel otherwise writes the raw construction exception before returning an emergency logger.
        throw new RuntimeException('Reporting log channel is unavailable.');
    }
}
