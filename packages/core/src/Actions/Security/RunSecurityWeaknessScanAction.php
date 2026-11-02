<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Data\Security\SecurityScanResultData;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Build the inventory, run every registered check, advisory source and the
 * bound analyser, and reconcile the stored findings: new fingerprints open,
 * known ones refresh last_detected_at, active ones not seen resolve, dismissed
 * ones stay dismissed. Each check runs in isolation so one failure cannot hide
 * the others' findings.
 */
final class RunSecurityWeaknessScanAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $includeAnalyser = true): SecurityScanResultData
    {
        throw new LogicException(sprintf('RunSecurityWeaknessScanAction is not implemented yet (includeAnalyser=%s).', $includeAnalyser ? 'true' : 'false'));
    }
}
