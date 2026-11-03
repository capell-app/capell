<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Support\Security\Remediations\DebugEnabledRemediation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/** @extends Factory<SecurityRemediationApproval> */
final class SecurityRemediationApprovalFactory extends Factory
{
    protected $model = SecurityRemediationApproval::class;

    #[Override]
    public function definition(): array
    {
        return ['weakness_id' => 'app.debug_enabled', 'approved_at' => now(), 'remediation_class' => DebugEnabledRemediation::class];
    }
}
