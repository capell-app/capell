<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph\Extractors;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\ContentGraph\ContentGraphNodeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Registers FoundOnPage edges for pages embedded in a page's composed block
 * content, so graph-based cache invalidation reaches the embedding page when
 * an embedded page changes.
 *
 * Widgets, page reference fields, and curated listings persist embedded pages
 * under the PageSelect state conventions: `page_id` and `pageable_id` hold a
 * single page key, `page_ids` and `pages` hold lists of page keys. A
 * `pageable_id` accompanied by a `pageable_type` that does not morph-resolve
 * to a Page is not a page embed. The `__capell` envelope carries system state,
 * never authored references.
 */
final class PageEmbedContentGraphExtractor implements ContentGraphExtractor
{
    private const string SOURCE_PACKAGE = 'capell-app/core';

    private const string SYSTEM_ENVELOPE_KEY = '__capell';

    private const array SINGLE_REFERENCE_KEYS = ['page_id', 'pageable_id'];

    private const array LIST_REFERENCE_KEYS = ['page_ids', 'pages'];

    private const int MAX_DEPTH = 64;

    private const int MAX_NODES = 10_000;

    public static function sourceModel(): string
    {
        return Page::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        if (! $model instanceof Page) {
            return ContentGraphEdgeCollectionData::make();
        }

        $embeddedPageIds = [];

        foreach ($model->translations()->get() as $translation) {
            $translation->setRelation('translatable', $model);
            $content = $translation->content;

            if (! is_array($content)) {
                continue;
            }

            foreach ($this->collectEmbeddedPageIds($content) as $embeddedPageId) {
                $embeddedPageIds[$embeddedPageId] = true;
            }
        }

        unset($embeddedPageIds[(int) $model->getKey()]);

        if ($embeddedPageIds === []) {
            return ContentGraphEdgeCollectionData::make();
        }

        ksort($embeddedPageIds);

        $source = ContentGraphNodeData::fromModel($model);
        $siteId = $this->integerAttribute($model, 'site_id');
        $edges = [];

        foreach (array_keys($embeddedPageIds) as $embeddedPageId) {
            $edges[] = new ContentGraphEdgeData(
                source: $source,
                target: ContentGraphNodeData::fromModelIdentity(Page::class, $embeddedPageId),
                kind: ContentGraphEdgeKind::FoundOnPage,
                strength: ContentGraphEdgeStrength::Weak,
                sourcePackage: self::SOURCE_PACKAGE,
                siteId: $siteId,
            );
        }

        return ContentGraphEdgeCollectionData::make($edges);
    }

    /**
     * @param  array<int|string, mixed>  $content
     * @return list<int>
     */
    private function collectEmbeddedPageIds(array $content): array
    {
        $embeddedPageIds = [];
        $pending = [[$content, 0]];
        $visitedNodes = 0;

        while ($pending !== []) {
            [$node, $depth] = array_pop($pending);

            if (++$visitedNodes > self::MAX_NODES) {
                break;
            }

            foreach ($node as $key => $value) {
                if ($key === self::SYSTEM_ENVELOPE_KEY) {
                    continue;
                }

                if (is_string($key) && in_array($key, self::SINGLE_REFERENCE_KEYS, true)) {
                    $embeddedPageId = $this->positivePageId($value);

                    if ($embeddedPageId !== null && $this->isEmbeddedPageReference($node, $key)) {
                        $embeddedPageIds[] = $embeddedPageId;
                    }

                    continue;
                }

                if (is_string($key) && in_array($key, self::LIST_REFERENCE_KEYS, true) && is_array($value)) {
                    foreach ($value as $candidate) {
                        $embeddedPageId = $this->positivePageId($candidate);

                        if ($embeddedPageId !== null) {
                            $embeddedPageIds[] = $embeddedPageId;
                        }
                    }
                }

                if (is_array($value) && $depth + 1 < self::MAX_DEPTH) {
                    $pending[] = [$value, $depth + 1];
                }
            }
        }

        return $embeddedPageIds;
    }

    /**
     * @param  array<int|string, mixed>  $node
     */
    private function isEmbeddedPageReference(array $node, string $referenceKey): bool
    {
        if ($referenceKey !== 'pageable_id') {
            return true;
        }

        $declaredType = $node['pageable_type'] ?? null;

        if (! is_string($declaredType) || $declaredType === '') {
            return true;
        }

        $modelClass = Relation::getMorphedModel($declaredType) ?? $declaredType;

        return is_a($modelClass, Page::class, true);
    }

    private function positivePageId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $embeddedPageId = (int) $value;

        return $embeddedPageId > 0 ? $embeddedPageId : null;
    }

    private function integerAttribute(Model $model, string $attribute): ?int
    {
        $value = $model->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : null;
    }
}
