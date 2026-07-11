<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Data\AssetData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\AssetAttachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

trait HasAssets
{
    /**
     * @return HasMany<AssetAttachment, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(AssetAttachment::class, 'related_id')
            ->where('related_type', $this->getMorphClass());
    }

    /**
     * @return HasMany<AssetAttachment, $this>
     */
    public function assetRelations(): HasMany
    {
        return $this->hasMany(AssetAttachment::class, 'asset_id')
            ->where('asset_type', $this->getMorphClass());
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function scopeWithAssets(Builder $query): void
    {
        $types = CapellCore::getAssets()->map(fn (AssetData $asset): string => $asset->model)->values()->all();

        $query->whereHas('assets', function (Builder $assetQuery) use ($types): void {
            $assetQuery->whereHasMorph('asset', $types);
        });

        /** @var array<class-string<Model>, list<string>> $morphRelations */
        $morphRelations = [];

        foreach (CapellCore::getAssets() as $asset) {
            $morphRelations[$asset->model] = method_exists($asset->model, 'getMorphRelations')
                ? $asset->model::getMorphRelations()
                : [];
        }

        $query->with('assets.asset', function (Relation $relation) use ($morphRelations): Relation {
            if ($relation instanceof MorphTo) {
                $relation->morphWith($morphRelations);
            }

            return $relation;
        });
    }
}
