<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\Remediations\DebugEnabledRemediation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/** @extends Factory<SecurityRemediationRun> */
final class SecurityRemediationRunFactory extends Factory
{
    protected $model = SecurityRemediationRun::class;

    #[Override]
    public function definition(): array
    {
        return [
            'finding_id' => SecurityWeaknessFinding::factory(), 'weakness_id' => 'app.debug_enabled',
            'remediation_class' => DebugEnabledRemediation::class, 'actor' => 'test',
            'outcome' => SecurityRemediationOutcome::Skipped, 'message' => 'No change needed.',
            'started_at' => now(), 'finished_at' => now(),
        ];
    }
}
