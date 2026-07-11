<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\Core\Contracts\Media\MediaContract;
use Closure;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Override;

class MediaLibraryImageColumn extends ImageColumn
{
    protected bool $autoEagerLoadRelation = true;

    protected string|Closure|null $collection = null;

    protected string|Closure|null $conversion = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.image'))
            ->alignCenter()
            ->width(0);
    }

    public function collection(string|Closure|null $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function conversion(string|Closure|null $conversion): static
    {
        $this->conversion = $conversion;

        return $this;
    }

    public function autoEagerLoadRelation(bool $condition = true): static
    {
        $this->autoEagerLoadRelation = $condition;

        return $this;
    }

    #[Override]
    public function getState(): mixed
    {
        $record = $this->getRecord();

        if (! $record instanceof Model) {
            return parent::getState();
        }

        $state = $this->resolveStateFromRecord($record, $this->getName());

        if ($this->hasRenderableState($state)) {
            return $state;
        }

        return $this->normaliseState(parent::getState());
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @return Builder<Model>|Relation<Model, Model, mixed>
     */
    #[Override]
    public function applyEagerLoading(Relation|Builder $query): Builder|Relation
    {
        if (! $this->autoEagerLoadRelation) {
            return $query;
        }

        $model = $query instanceof Relation ? $query->getQuery()->getModel() : $query->getModel();
        $relationshipName = $this->resolveEagerLoadRelationshipName($model);

        if ($relationshipName === null) {
            return $query;
        }

        $query->with($relationshipName);

        return $query;
    }

    protected function resolveStateFromRecord(Model $record, string $name): mixed
    {
        $recordOrRecords = $this->resolveOwnerFromName($record, $name);

        if ($recordOrRecords instanceof Collection) {
            return $recordOrRecords
                ->map(fn (mixed $record): mixed => $this->resolveStateFromOwner($record))
                ->filter(fn (mixed $state): bool => $this->hasRenderableState($state))
                ->values()
                ->all();
        }

        return $this->resolveStateFromOwner($recordOrRecords);
    }

    protected function resolveOwnerFromName(Model $record, string $name): mixed
    {
        $nameParts = explode('.', $name);

        if (count($nameParts) === 1) {
            return $record;
        }

        while (count($nameParts) > 1) {
            $namePart = array_shift($nameParts);

            if ($record->hasAttribute($namePart)) {
                return data_get($record, implode('.', [$namePart, ...$nameParts]));
            }

            if (! $record->isRelation($namePart)) {
                return data_get($record, implode('.', [$namePart, ...$nameParts]));
            }

            $relatedRecord = $record->getRelationValue($namePart);

            if ($relatedRecord instanceof Collection) {
                $remainingName = implode('.', $nameParts);

                return $relatedRecord
                    ->map(fn (Model $record): mixed => $this->resolveOwnerFromName($record, $remainingName))
                    ->filter();
            }

            if (! $relatedRecord instanceof Model) {
                return null;
            }

            $record = $relatedRecord;
        }

        return $record;
    }

    protected function resolveStateFromOwner(mixed $owner): mixed
    {
        if ($owner instanceof HasMediaContract) {
            $url = $owner->getFirstMediaUrl($this->getCollectionName(), $this->getConversionName());

            return filled($url) ? $url : null;
        }

        return $this->normaliseState($owner);
    }

    protected function normaliseState(mixed $state): mixed
    {
        if ($state instanceof Collection) {
            return $state
                ->map(fn (mixed $state): mixed => $this->normaliseState($state))
                ->filter(fn (mixed $state): bool => $this->hasRenderableState($state))
                ->values()
                ->all();
        }

        if ($state instanceof MediaContract) {
            return $state->getUrl($this->getConversionName());
        }

        if ($state instanceof Model && $state->hasAttribute('original_url')) {
            return $state->getAttribute('original_url');
        }

        return $state;
    }

    protected function hasRenderableState(mixed $state): bool
    {
        if ($state instanceof Collection) {
            return $state->isNotEmpty();
        }

        if (is_array($state)) {
            return $state !== [];
        }

        return filled($state);
    }

    protected function getCollectionName(): string
    {
        $collection = $this->evaluate($this->collection);

        if (filled($collection)) {
            return (string) $collection;
        }

        return (string) str($this->getName())->afterLast('.');
    }

    protected function getConversionName(): string
    {
        $conversion = $this->evaluate($this->conversion);

        return filled($conversion) ? (string) $conversion : '';
    }

    protected function resolveEagerLoadRelationshipName(Model $model): ?string
    {
        $nameParts = explode('.', $this->getName());
        $relationshipParts = [];

        foreach ($nameParts as $namePart) {
            if ($model->hasAttribute($namePart)) {
                break;
            }

            if (! $model->isRelation($namePart)) {
                break;
            }

            $relationshipParts[] = $namePart;
            $model = $model->{$namePart}()->getRelated();
        }

        if ($relationshipParts === []) {
            $collectionName = $this->getCollectionName();

            return $model->isRelation($collectionName) ? $collectionName : null;
        }

        return implode('.', $relationshipParts);
    }
}
