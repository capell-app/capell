<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Record an operator's consent for Capell to fix one kind of weakness on its
 * own. Only weaknesses with a registered remediation can be approved, so the
 * approval always names something Capell can actually do.
 */
final class ApproveSecurityRemediationAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityWeaknessRegistry $registry) {}

    public static function userId(Authenticatable $user): ?int
    {
        $identifier = $user->getAuthIdentifier();

        return is_int($identifier) || (is_string($identifier) && ctype_digit($identifier)) ? (int) $identifier : null;
    }

    public function handle(string $weaknessId, Authenticatable $user, ?string $note = null): SecurityRemediationApproval
    {
        if (! $this->registry->remediation($weaknessId) instanceof SecurityWeaknessRemediation) {
            throw new InvalidArgumentException(sprintf('No automatic fix is registered for security weakness "%s".', $weaknessId));
        }

        $approval = SecurityRemediationApproval::query()->firstOrNew(['weakness_id' => $weaknessId]);
        $approval->fill([
            'approved_by' => self::userId($user),
            'approved_at' => CarbonImmutable::now(),
            'revoked_by' => null,
            'revoked_at' => null,
            'note' => $note,
        ]);
        $approval->save();

        return $approval;
    }
}
