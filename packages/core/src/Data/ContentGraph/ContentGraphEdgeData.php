<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Spatie\LaravelData\Data;

final class ContentGraphEdgeData extends Data
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly ContentGraphNodeData $source,
        public readonly ContentGraphNodeData $target,
        public readonly ContentGraphEdgeKind|string $kind,
        public readonly ContentGraphEdgeStrength $strength,
        public readonly string $sourcePackage,
        public readonly ?int $siteId = null,
        public readonly ?int $languageId = null,
        public readonly ?array $metadata = null,
    ) {}
}
