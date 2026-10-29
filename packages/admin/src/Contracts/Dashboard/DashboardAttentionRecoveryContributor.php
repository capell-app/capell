<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardAttentionRecoveryCandidateData;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional capability for a tagged attention contributor.
 * Return true only after fresh evidence confirms this contributor's issue is resolved.
 * Return false for unrelated keys, disabled or unavailable capabilities, and uncertainty.
 *
 * @experimental
 */
interface DashboardAttentionRecoveryContributor
{
    public function isRecovered(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool;

    /** Return true only when this contributor's issue history remains visible to the actor. */
    public function canViewHistory(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool;
}
