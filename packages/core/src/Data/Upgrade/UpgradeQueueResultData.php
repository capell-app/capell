<?php

declare(strict_types=1);

namespace Capell\Core\Data\Upgrade;

use Capell\Core\Enums\Upgrade\UpgradeQueueStatus;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;

final readonly class UpgradeQueueResultData
{
    /**
     * @param  list<string>  $manualCommands
     */
    public function __construct(
        public ?int $runId,
        public UpgradeRunStatus $runStatus,
        public UpgradeQueueStatus $queueStatus,
        public UpgradeReadinessReportData $readiness,
        public array $manualCommands,
    ) {}

    public function queued(): bool
    {
        return $this->queueStatus === UpgradeQueueStatus::Queued;
    }
}
