<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Contracts\UpgradeStepContract;
use Spatie\LaravelData\Data;

class UpgradePlanData extends Data
{
    /**
     * @param  array<int, UpgradeStepContract>  $pendingSteps
     */
    public function __construct(
        public readonly array $pendingSteps,
        public readonly UpgradeContext $context,
        public readonly VersionAudit $versionAudit,
    ) {}
}
