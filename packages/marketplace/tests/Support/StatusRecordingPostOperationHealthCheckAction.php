<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use BackedEnum;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;

/**
 * A passing health check that records the attempt statuses it saw while it ran,
 * so a test can prove the check happened before the install was finalised.
 */
final class StatusRecordingPostOperationHealthCheckAction
{
    /** @var list<string> */
    public array $observedStatuses = [];

    /**
     * The budget the job handed over. Recorded rather than ignored, because it
     * is part of the signature this class stands in for: a double that quietly
     * dropped it would stop standing in for the real action.
     */
    public ?int $observedBudgetSeconds = null;

    public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
    {
        $this->observedBudgetSeconds = $budgetSeconds;

        $this->observedStatuses = array_values(
            MarketplaceInstallAttempt::query()
                ->pluck('status')
                ->map(fn (mixed $status): string => $status instanceof BackedEnum ? (string) $status->value : (string) $status)
                ->all(),
        );

        return new MarketplaceHealthCheckResultData(
            bootProbe: MarketplaceHealthProbeOutcome::Passed,
            httpProbe: MarketplaceHealthProbeOutcome::Skipped,
        );
    }
}
