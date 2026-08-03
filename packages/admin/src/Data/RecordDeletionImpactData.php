<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

final class RecordDeletionImpactData extends Data
{
    public function __construct(
        public readonly int $knownReferenceCount,
        public readonly bool $authoritative,
        public readonly string $noReferencesLabel,
        public readonly ?string $affectedLabel = null,
        public readonly ?string $referencesUrl = null,
        public readonly ?string $reviewLabel = null,
    ) {}
}
