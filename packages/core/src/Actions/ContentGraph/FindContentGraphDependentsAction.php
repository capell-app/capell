<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Models\ContentGraphEdge;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindContentGraphDependentsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  class-string<Model>  $targetType
     * @return Collection<int, ContentGraphEdge>
     */
    public function handle(string $targetType, int $targetId): Collection
    {
        return ContentGraphEdge::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->get();
    }
}
