<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Data\Security\SecurityRemediationBatchResultData;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Apply the automatic fix for every open, remediable finding whose weakness
 * has an active {@see SecurityRemediationApproval} and a
 * registered remediation, and whose source may remediate automatically.
 * Records one {@see SecurityRemediationRun} per attempt.
 */
final class ApplyApprovedSecurityRemediationsAction
{
    use AsFake;
    use AsObject;

    public function handle(string $actor, bool $dryRun = false, ?string $onlyWeaknessId = null): SecurityRemediationBatchResultData
    {
        throw new LogicException(sprintf('ApplyApprovedSecurityRemediationsAction is not implemented yet (actor=%s, dryRun=%s, onlyWeaknessId=%s).', $actor, $dryRun ? 'true' : 'false', $onlyWeaknessId ?? 'null'));
    }
}
