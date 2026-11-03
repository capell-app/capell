<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;

/**
 * The automatic fix for one known weakness.
 *
 * A remediation runs only when an operator has approved automatic fixing for
 * its `weaknessId()` and the finding came from a source that may remediate
 * automatically. It must be idempotent (applying it to an already-fixed
 * installation is a no-op that reports `applied: false`), must record what
 * it changed, and must never need interactive input.
 *
 * @experimental
 */
interface SecurityWeaknessRemediation
{
    public const string TAG = 'capell.security.weakness_remediation';

    public function weaknessId(): string;

    /** Plain-language description of the change, shown before approval. */
    public function describe(): string;

    public function isReversible(): bool;

    public function apply(SecurityWeaknessFindingData $finding): SecurityRemediationOutcomeData;
}
