<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph\Extractors;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\ContentGraph\ContentGraphNodeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class MediaContentGraphExtractor implements ContentGraphExtractor
{
    private const string SOURCE_PACKAGE = 'capell-app/core';

    public static function sourceModel(): string
    {
        return Media::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        if (! $model instanceof Media) {
            return ContentGraphEdgeCollectionData::make();
        }

        $ownerType = $this->resolveMorphType($model->model_type);
        if ($ownerType === null) {
            return ContentGraphEdgeCollectionData::make();
        }

        return ContentGraphEdgeCollectionData::make([
            new ContentGraphEdgeData(
                source: ContentGraphNodeData::fromModelIdentity(Media::class, (int) $model->getKey()),
                target: ContentGraphNodeData::fromModelIdentity($ownerType, $model->model_id),
                kind: ContentGraphEdgeKind::UsesMedia,
                strength: ContentGraphEdgeStrength::Informational,
                sourcePackage: self::SOURCE_PACKAGE,
            ),
        ]);
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
}
