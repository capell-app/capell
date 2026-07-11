<?php

declare(strict_types=1);

namespace Capell\Core\Data\Upgrade;

use Capell\Core\Enums\Upgrade\UpgradeReadinessResult;

final readonly class UpgradeReadinessReportData
{
    /**
     * @param  list<UpgradeReadinessCheckData>  $checks
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    public function __construct(
        public UpgradeReadinessResult $result,
        public array $checks,
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public function canQueue(): bool
    {
        return $this->result === UpgradeReadinessResult::Ready && $this->errors === [];
    }
}
