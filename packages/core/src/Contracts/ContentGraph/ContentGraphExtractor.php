<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\ContentGraph;

use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Illuminate\Database\Eloquent\Model;

interface ContentGraphExtractor
{
    /**
     * @return class-string<Model>
     */
    public static function sourceModel(): string;

    public function extract(Model $model): ContentGraphEdgeCollectionData;
}
