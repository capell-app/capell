<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Closure;
use RuntimeException;

/**
 * Proof that a write to an out-of-band public artefact store (an HTML cache
 * disk copy, a static export) is happening inside PublishPublicArtefactAction:
 * under the publication lock, after the write-time eligibility check. Only
 * that action can issue one, and it is revoked when the write returns, so a
 * store that requires an active permit cannot be written any other way.
 *
 * @experimental
 */
final class PublicArtefactPermit
{
    /** @param Closure(): bool $active */
    private function __construct(private readonly Closure $active) {}

    public function isActive(): bool
    {
        return ($this->active)();
    }

    public function assertActive(): void
    {
        throw_unless($this->isActive(), RuntimeException::class, 'Public artefacts may only be written through PublishPublicArtefactAction.');
    }
}
