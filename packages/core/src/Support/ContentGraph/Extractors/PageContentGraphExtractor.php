<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph\Extractors;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\ContentGraph\ContentGraphNodeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class PageContentGraphExtractor implements ContentGraphExtractor
{
    private const string SOURCE_PACKAGE = 'capell-app/core';

    public static function sourceModel(): string
    {
        return Page::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        if (! $model instanceof Page) {
            return ContentGraphEdgeCollectionData::make();
        }

        $source = ContentGraphNodeData::fromModel($model);
        $siteId = $this->integerAttribute($model, 'site_id');
        $edges = [];

        if ($this->integerAttribute($model, 'layout_id') !== null) {
            $edges[] = $this->edge(
                source: $source,
                target: ContentGraphNodeData::fromModelIdentity(Layout::class, $model->layout_id),
                kind: ContentGraphEdgeKind::UsesLayout,
                strength: ContentGraphEdgeStrength::Strong,
                siteId: $siteId,
            );
        }

        if ($siteId !== null) {
            $edges[] = $this->edge(
                source: $source,
                target: ContentGraphNodeData::fromModelIdentity(Site::class, $siteId),
                kind: ContentGraphEdgeKind::BelongsToSite,
                strength: ContentGraphEdgeStrength::Strong,
                siteId: $siteId,
            );
        }

        $canonicalType = data_get($model->meta, 'canonical_pageable_type');
        $canonicalId = data_get($model->meta, 'canonical_pageable_id');
        if (is_string($canonicalType) && is_numeric($canonicalId)) {
            $canonicalModelType = $this->resolveMorphType($canonicalType);
            if ($canonicalModelType !== null) {
                $edges[] = $this->edge(
                    source: $source,
                    target: ContentGraphNodeData::fromModelIdentity($canonicalModelType, (int) $canonicalId),
                    kind: ContentGraphEdgeKind::CanonicalizesTo,
                    strength: ContentGraphEdgeStrength::Strong,
                    siteId: $siteId,
                );
            }
        }

        foreach ((array) data_get($model->meta, 'related', []) as $relatedPageId) {
            if (! is_numeric($relatedPageId)) {
                continue;
            }

            $edges[] = $this->edge(
                source: $source,
                target: ContentGraphNodeData::fromModelIdentity(Page::class, (int) $relatedPageId),
                kind: ContentGraphEdgeKind::RelatesToPage,
                strength: ContentGraphEdgeStrength::Weak,
                siteId: $siteId,
            );
        }

        foreach ([$model->image, $model->socialImage] as $media) {
            if (! $media instanceof Media) {
                continue;
            }

            $edges[] = $this->edge(
                source: $source,
                target: ContentGraphNodeData::fromModelIdentity(Media::class, (int) $media->getKey()),
                kind: ContentGraphEdgeKind::UsesMedia,
                strength: ContentGraphEdgeStrength::Strong,
                siteId: $siteId,
            );
        }

        return ContentGraphEdgeCollectionData::make($edges);
    }

    /**
     * @return class-string<Model>|null
     */
    private function resolveMorphType(string $targetType): ?string
    {
        $modelClass = Relation::getMorphedModel($targetType) ?? $targetType;

        if (! is_a($modelClass, Model::class, true)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        return $modelClass;
    }

    private function integerAttribute(Model $model, string $attribute): ?int
    {
        $value = $model->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : null;
    }

    private function edge(
        ContentGraphNodeData $source,
        ContentGraphNodeData $target,
        ContentGraphEdgeKind $kind,
        ContentGraphEdgeStrength $strength,
        ?int $siteId = null,
    ): ContentGraphEdgeData {
        return new ContentGraphEdgeData(
            source: $source,
            target: $target,
            kind: $kind,
            strength: $strength,
            sourcePackage: self::SOURCE_PACKAGE,
            siteId: $siteId,
        );
    }
}
