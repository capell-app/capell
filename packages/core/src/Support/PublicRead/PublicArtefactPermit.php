<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Contracts\PublicRead\PublicArtefactFence;
use Closure;
use RuntimeException;

/**
 * Proof that a write to an out-of-band public artefact store (an HTML cache
 * disk copy, a static export) is happening inside PublishPublicArtefactAction:
 * under that store's own lock, after the write-time eligibility check. Only
 * that action can issue one, it is bound to the store whose lock was taken,
 * and it is revoked when the write returns, so a store that requires an
 * active permit cannot be written any other way.
 *
 * @experimental
 */
final class PublicArtefactPermit
{
    /** @param Closure(): bool $active */
    private function __construct(
        private readonly Closure $active,
        private readonly string $fenceIdentity,
    ) {}

    public function isActive(): bool
    {
        return ($this->active)();
    }

    public function assertActiveFor(PublicArtefactFence $store): void
    {
        throw_unless(
            $this->isActive() && hash_equals($this->fenceIdentity, $store->fenceIdentity()),
            RuntimeException::class,
            'Public artefacts may only be written through PublishPublicArtefactAction, under the lock of the store being written.',
        );
    }
}
