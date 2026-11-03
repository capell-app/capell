<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityEvidence;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Record an operator's consent for a scanned finding and its evidence.
 * Only findings with a registered remediation can be approved.
 */
class ApproveSecurityRemediationAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly SecurityWeaknessRegistry $registry) {}

    public static function userId(Authenticatable $user): ?int
    {
        $identifier = $user->getAuthIdentifier();

        return is_int($identifier) || (is_string($identifier) && ctype_digit($identifier)) ? (int) $identifier : null;
    }

    public function handle(string $weaknessId, Authenticatable $user, ?string $note = null, ?string $fingerprint = null): SecurityRemediationApproval
    {
        $remediation = $this->registry->remediation($weaknessId);
        if (! $remediation instanceof SecurityWeaknessRemediation) {
            throw new InvalidArgumentException(__('capell-core::security.remediation.no_fix', ['id' => $weaknessId]));
        }

        $query = SecurityWeaknessFinding::query()->where('weakness_id', $weaknessId)->where('status', SecurityFindingStatus::Open->value)->where('source', '!=', SecurityFindingSource::Analyser->value)->where('remediable', true);
        if ($fingerprint !== null) {
            $query->where('fingerprint', $fingerprint);
        }

        $findings = $query->get();
        $finding = $findings->first();
        if ($findings->count() !== 1 || ! $finding instanceof SecurityWeaknessFinding || $finding->last_detected_scan_id === null || $finding->evidence_hash === null || ! SecurityEvidence::complete($finding->evidence ?? []) || ! hash_equals($finding->evidence_hash, SecurityEvidence::hash($finding->evidence ?? []))) {
            throw new InvalidArgumentException(__('capell-core::security.remediation.approval_requires_finding'));
        }

        $approval = SecurityRemediationApproval::query()->firstOrNew(['weakness_id' => $weaknessId]);
        $approval->fill([
            'remediation_class' => $remediation::class,
            'approved_for_scan_id' => $finding->last_detected_scan_id,
            'approved_fingerprint' => $finding->fingerprint,
            'evidence_hash' => SecurityEvidence::hash($finding->evidence ?? []),
            'superseded_at' => null,
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
