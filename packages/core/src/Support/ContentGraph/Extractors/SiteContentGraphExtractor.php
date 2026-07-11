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
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;

class SiteContentGraphExtractor implements ContentGraphExtractor
{
    private const string SOURCE_PACKAGE = 'capell-app/core';

    public static function sourceModel(): string
    {
        return Site::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        if (! $model instanceof Site) {
            return ContentGraphEdgeCollectionData::make();
        }

        $source = ContentGraphNodeData::fromModelIdentity(Site::class, (int) $model->getKey());
        $siteId = (int) $model->getKey();
        $edges = [];

        if ($this->integerAttribute($model, 'language_id') !== null) {
            $edges[] = $this->edge($source, ContentGraphNodeData::fromModelIdentity(Language::class, $model->language_id), ContentGraphEdgeKind::BelongsToLanguage, ContentGraphEdgeStrength::Strong, $siteId);
        }

        if ($this->integerAttribute($model, 'theme_id') !== null) {
            $edges[] = $this->edge($source, ContentGraphNodeData::fromModelIdentity(Theme::class, $model->theme_id), ContentGraphEdgeKind::UsesTheme, ContentGraphEdgeStrength::Strong, $siteId);
        }

        foreach (['image', 'logo', 'logoInverted', 'favicon', 'socialImage'] as $relationName) {
            if (! method_exists($model, $relationName)) {
                continue;
            }

            $media = $model->getAttribute($relationName);
            if (! $media instanceof Media) {
                continue;
            }

            $edges[] = $this->edge($source, ContentGraphNodeData::fromModelIdentity(Media::class, (int) $media->getKey()), ContentGraphEdgeKind::UsesMedia, ContentGraphEdgeStrength::Strong, $siteId);
        }

        foreach ((array) data_get($model->meta, 'related', []) as $relatedSiteId) {
            if (! is_numeric($relatedSiteId)) {
                continue;
            }

            $edges[] = $this->edge($source, ContentGraphNodeData::fromModelIdentity(Site::class, (int) $relatedSiteId), ContentGraphEdgeKind::RelatesToPage, ContentGraphEdgeStrength::Weak, $siteId);
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
