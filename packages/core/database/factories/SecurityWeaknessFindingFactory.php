<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityWeaknessFinding;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/** @extends Factory<SecurityWeaknessFinding> */
final class SecurityWeaknessFindingFactory extends Factory
{
    protected $model = SecurityWeaknessFinding::class;

    #[Override]
    public function definition(): array
    {
        return [
            'weakness_id' => 'app.debug_enabled', 'fingerprint' => hash('sha256', $this->faker->uuid()),
            'source' => SecurityFindingSource::Builtin, 'severity' => SecurityWeaknessSeverity::High,
            'status' => SecurityFindingStatus::Open, 'title' => 'Debug mode is enabled',
            'summary' => 'Debug mode may expose information.', 'remediable' => true,
            'first_detected_at' => now(), 'last_detected_at' => now(),
        ];
    }
}
