<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Capell\Core\Contracts\Security\SecurityAdvisorySource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Spatie\LaravelData\Data;

/**
 * A published vulnerability that affects an installed Composer package.
 *
 * Core never fetches these itself; a {@see SecurityAdvisorySource}
 * (the Marketplace heartbeat snapshot, a Composer audit, a vendor feed) supplies
 * them already matched to this installation.
 */
final class SecurityAdvisoryData extends Data
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $composerName,
        public readonly SecurityWeaknessSeverity $severity,
        public readonly string $title,
        public readonly ?string $affectedVersions = null,
        public readonly ?string $fixedVersion = null,
        public readonly ?string $url = null,
        public readonly ?string $source = null,
    ) {}
}
