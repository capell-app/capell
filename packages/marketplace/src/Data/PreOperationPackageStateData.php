<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

/**
 * What vendor/ held before a Marketplace operation: one fingerprint per
 * installed package and the content hash of every installed non-Core
 * extension, so a failure can name what changed and a rollback can prove it
 * put the same bytes back.
 */
final class PreOperationPackageStateData extends Data
{
    /**
     * @param  array<string, string>|null  $installedFingerprints  Null when installed.json could not be read before the operation.
     * @param  array<string, string>  $contentHashes
     */
    public function __construct(
        public readonly ?array $installedFingerprints,
        public readonly array $contentHashes,
    ) {}
}
