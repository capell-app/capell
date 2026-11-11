<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph;

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Page;
use Capell\Core\Models\Term;
use Capell\Core\Support\ContentGraph\Extractors\PagePropertyValueContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\PropertyValueContentGraphExtractor;
use Capell\Core\Support\ContentGraph\Extractors\TermPropertyValueContentGraphExtractor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** Drops stored property-reference edges whose value row no longer exists. */
final class LivePropertyReferenceEdges
{
    /**
     * @param  Collection<int, ContentGraphEdge>  $edges
     * @return Collection<int, ContentGraphEdge>
     */
    public static function filter(Collection $edges): Collection
    {
        return $edges
            ->filter(static fn (ContentGraphEdge $edge): bool => self::isLive($edge))
            ->values();
    }

    private static function isLive(ContentGraphEdge $edge): bool
    {
        if ($edge->getAttribute('kind') !== ContentGraphEdgeKind::ReferencedByProperty->value) {
            return true;
        }

        $extractor = self::extractorFor((string) $edge->getAttribute('source_type'));
        $targetType = (string) $edge->getAttribute('target_type');

        if (! $extractor instanceof PropertyValueContentGraphExtractor || ! is_a($targetType, Model::class, true)) {
            return true;
        }

        return $extractor->stillReferences(
            (int) $edge->getAttribute('source_id'),
            $targetType,
            (int) $edge->getAttribute('target_id'),
        );
    }

    private static function extractorFor(string $sourceType): ?PropertyValueContentGraphExtractor
    {
        return match (true) {
            is_a($sourceType, Page::class, true) => new PagePropertyValueContentGraphExtractor,
            is_a($sourceType, Term::class, true) => new TermPropertyValueContentGraphExtractor,
            default => null,
        };
    }
}
