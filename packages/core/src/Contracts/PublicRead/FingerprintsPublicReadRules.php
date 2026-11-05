<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\PublicRead;

/**
 * Implemented by a public-read eligibility contributor whose decisions come
 * from stored data (areas, policies, allowlists). The fingerprint is a digest
 * of every stored input those decisions depend on, so a change is detected
 * from the data itself even when nothing announced it: a crashed request, a
 * failed revision write, a direct database edit or a lost cache.
 *
 * The public-read reconciler compares it with the fingerprint recorded beside
 * the durable revision every minute and advances the revision when they
 * differ. Throw when the inputs cannot be read; the reconciler then treats
 * every stored copy as stale.
 *
 * @experimental
 */
interface FingerprintsPublicReadRules
{
    public function publicReadRulesFingerprint(): string;
}
