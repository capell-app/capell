<?php

declare(strict_types=1);

namespace Capell\Admin\Data\ContentGraph;

use Capell\Core\Data\ContentGraph\ContentImpactPreviewData;
use Spatie\LaravelData\Data;

final class DeleteImpactValidationData extends Data
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $blockingCount,
        public readonly int $warningCount,
        public readonly ContentImpactPreviewData $preview,
    ) {}
}
