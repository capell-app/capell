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
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;

final class LayoutContentGraphExtractor implements ContentGraphExtractor
{
    private const string SOURCE_PACKAGE = 'capell-app/core';

    public static function sourceModel(): string
    {
        return Layout::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        if (! $model instanceof Layout) {
            return ContentGraphEdgeCollectionData::make();
        }

        $source = ContentGraphNodeData::fromModel($model);
        $siteId = $this->integerAttribute($model, 'site_id');
        $edges = [];

        if ($siteId !== null) {
            $edges[] = $this->edge($source, ContentGraphNodeData::fromModelIdentity(Site::class, $siteId), ContentGraphEdgeKind::BelongsToSite, ContentGraphEdgeStrength::Strong, $siteId);
        }

        if ($this->integerAttribute($model, 'theme_id') !== null) {
            $edges[] = $this->edge($source, ContentGraphNodeData::fromModelIdentity(Theme::class, (int) $model->theme_id), ContentGraphEdgeKind::UsesTheme, ContentGraphEdgeStrength::Strong, $siteId);
        }

        if ($model->image instanceof Media) {
            $edges[] = $this->edge($source, ContentGraphNodeData::fromModelIdentity(Media::class, (int) $model->image->getKey()), ContentGraphEdgeKind::UsesMedia, ContentGraphEdgeStrength::Strong, $siteId);
        }

        return ContentGraphEdgeCollectionData::make($edges);
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
        return new ContentGraphEdgeData($source, $target, $kind, $strength, self::SOURCE_PACKAGE, $siteId);
    }
}
