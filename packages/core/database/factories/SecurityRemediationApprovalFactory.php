<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\Remediations\DebugEnabledRemediation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/** @extends Factory<SecurityRemediationApproval> */
final class SecurityRemediationApprovalFactory extends Factory
{
    protected $model = SecurityRemediationApproval::class;

    #[Override]
    public function configure(): static
    {
        return $this->afterMaking(function (SecurityRemediationApproval $approval): void {
            $finding = SecurityWeaknessFinding::query()->where('weakness_id', $approval->weakness_id)->where('source', '!=', 'analyser')->first();
            if ($finding !== null) {
                $approval->fill(['approved_fingerprint' => $finding->fingerprint, 'approved_for_scan_id' => $finding->last_detected_scan_id, 'evidence_hash' => $finding->evidence_hash]);
            }
        });
    }

    #[Override]
    public function definition(): array
    {
        return ['weakness_id' => 'app.debug_enabled', 'approved_at' => now(), 'remediation_class' => DebugEnabledRemediation::class];
    }
}
