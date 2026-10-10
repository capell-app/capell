<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Illuminate\Database\Eloquent\Model;
use Override;

final class AuditUnlistedContentGraphExtractor implements ContentGraphExtractor
{
    #[Override]
    public static function sourceModel(): string
    {
        return Model::class;
    }

    #[Override]
    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        return ContentGraphEdgeCollectionData::make();
    }
}
