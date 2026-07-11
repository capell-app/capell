<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;

final class TestPageContentGraphExtractor implements ContentGraphExtractor
{
    public static function sourceModel(): string
    {
        return Page::class;
    }

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        return ContentGraphEdgeCollectionData::make();
    }
}
