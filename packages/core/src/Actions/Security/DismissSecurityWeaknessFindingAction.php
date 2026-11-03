<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Models\SecurityWeaknessFinding;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Accept a finding's risk. A dismissed finding stays dismissed while its
 * fingerprint is unchanged; new evidence produces a new fingerprint and a
 * fresh open finding.
 */
class DismissSecurityWeaknessFindingAction
{
    use AsFake;
    use AsObject;

    public function handle(SecurityWeaknessFinding $finding, Authenticatable $user, ?string $note = null): SecurityWeaknessFinding
    {
        $finding->fill([
            'status' => SecurityFindingStatus::Dismissed,
            'dismissed_at' => CarbonImmutable::now(),
            'dismissed_by' => ApproveSecurityRemediationAction::userId($user),
            'dismissal_note' => $note,
        ]);
        $finding->save();

        return $finding;
    }
}
