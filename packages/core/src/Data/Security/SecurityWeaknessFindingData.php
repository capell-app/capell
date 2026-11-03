<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One detected weakness, as a check or analyser reports it.
 *
 * `weaknessId` names the kind of weakness (`app.debug_enabled`,
 * `extension.security_advisory`, …) and is what an operator approves a
 * remediation for. `fingerprint` identifies this particular occurrence
 * (the same weakness on two packages has two fingerprints) and is how the
 * scan recognises a finding it has seen before.
 */
final class SecurityWeaknessFindingData extends Data
{
    /**
     * @param  array<string, mixed>  $evidence  Plain facts a person can check; never secrets.
     */
    public function __construct(
        public readonly string $weaknessId,
        public readonly string $fingerprint,
        public readonly SecurityWeaknessSeverity $severity,
        public readonly SecurityFindingSource $source,
        public readonly string $title,
        public readonly string $summary,
        public readonly array $evidence = [],
        public readonly ?string $composerName = null,
        public readonly bool $remediable = false,
        public readonly ?string $recommendedAction = null,
        public readonly ?float $confidence = null,
        public readonly ?CarbonImmutable $detectedAt = null,
    ) {}

    public static function fingerprintFor(string $weaknessId, string ...$parts): string
    {
        return hash('sha256', implode("\0", [$weaknessId, ...$parts]));
    }
}
