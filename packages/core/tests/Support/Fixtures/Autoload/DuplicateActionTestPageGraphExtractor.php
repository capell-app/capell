<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\ContentGraph\ContentGraphNodeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;

final class DuplicateActionTestPageGraphExtractor implements ContentGraphExtractor
{
    public static function sourceModel(): string
    {
        return Page::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        $edge = new ContentGraphEdgeData(
            source: ContentGraphNodeData::fromModel($model),
            target: ContentGraphNodeData::fromModelIdentity(Layout::class, 42),
            kind: ContentGraphEdgeKind::UsesLayout,
            strength: ContentGraphEdgeStrength::Strong,
            sourcePackage: 'capell-app/core',
        );

        return ContentGraphEdgeCollectionData::make([
            $edge,
            $edge,
        ]);
    }
}
