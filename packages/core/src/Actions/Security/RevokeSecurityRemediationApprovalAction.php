<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Models\SecurityRemediationApproval;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Withdraw consent; the row stays as history and nothing already applied is undone. */
class RevokeSecurityRemediationApprovalAction
{
    use AsFake;
    use AsObject;

    public function handle(string $weaknessId, Authenticatable $user): ?SecurityRemediationApproval
    {
        $approval = SecurityRemediationApproval::query()->active()->where('weakness_id', $weaknessId)->first();

        if ($approval === null) {
            return null;
        }

        $approval->fill([
            'revoked_by' => ApproveSecurityRemediationAction::userId($user),
            'revoked_at' => CarbonImmutable::now(),
        ]);
        $approval->save();

        return $approval;
    }
}
