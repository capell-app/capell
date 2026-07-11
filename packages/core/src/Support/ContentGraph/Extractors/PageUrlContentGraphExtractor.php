<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph\Extractors;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\ContentGraph\ContentGraphNodeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class PageUrlContentGraphExtractor implements ContentGraphExtractor
{
    private const string SOURCE_PACKAGE = 'capell-app/core';

    public static function sourceModel(): string
    {
        return PageUrl::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        if (! $model instanceof PageUrl) {
            return ContentGraphEdgeCollectionData::make();
        }

        $source = ContentGraphNodeData::fromModel($model);
        $siteId = $this->integerAttribute($model, 'site_id');
        $languageId = $this->integerAttribute($model, 'language_id');
        $edges = [];

        if (is_string($model->pageable_type) && is_numeric($model->pageable_id)) {
            $pageableModelType = $this->resolveMorphType($model->pageable_type);
            if ($pageableModelType !== null) {
                $edges[] = new ContentGraphEdgeData(
                    source: $source,
                    target: ContentGraphNodeData::fromModelIdentity($pageableModelType, $model->pageable_id),
                    kind: ContentGraphEdgeKind::ResolvesToPage,
                    strength: ContentGraphEdgeStrength::Strong,
                    sourcePackage: self::SOURCE_PACKAGE,
                    siteId: $siteId,
                    languageId: $languageId,
                );
            }
        }

        if ($siteId !== null) {
            $edges[] = new ContentGraphEdgeData($source, ContentGraphNodeData::fromModelIdentity(Site::class, $siteId), ContentGraphEdgeKind::BelongsToSite, ContentGraphEdgeStrength::Strong, self::SOURCE_PACKAGE, $siteId, $languageId);
        }

        if ($languageId !== null) {
            $edges[] = new ContentGraphEdgeData($source, ContentGraphNodeData::fromModelIdentity(Language::class, $languageId), ContentGraphEdgeKind::BelongsToLanguage, ContentGraphEdgeStrength::Strong, self::SOURCE_PACKAGE, $siteId, $languageId);
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
}
