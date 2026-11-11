<?php

declare(strict_types=1);

namespace Capell\Core\Support\ContentGraph\Extractors;

use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Data\ContentGraph\ContentGraphNodeData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Indexes the records a page's or term's structured property values point
 * at (media, pages and terms), so delete-impact previews, retained-deletion
 * checks and cache invalidation see references that live outside composed
 * content.
 *
 * An edge is strong when the owning definition must be filled before the
 * owner can publish: removing the target would break that gate. Every other
 * reference is weak, so deleting the target is allowed with a warning.
 */
abstract class PropertyValueContentGraphExtractor implements ContentGraphExtractor
{
    private const string SOURCE_PACKAGE = 'capell-app/core';

    /** @return class-string<Model> */
    abstract protected function valueModel(): string;

    /** The value-table column that holds the owner's key. */
    abstract protected function ownerColumn(): string;

    /**
     * Reference columns on the value table, mapped to the model they point at.
     *
     * @return array<string, class-string<Model>>
     */
    abstract protected function referenceColumns(): array;

    abstract protected function siteId(Model $owner): ?int;

    public function extract(Model $model): ContentGraphEdgeCollectionData
    {
        if (! is_a($model, static::sourceModel())) {
            return ContentGraphEdgeCollectionData::make();
        }

        $references = $this->references($model);

        if ($references === []) {
            return ContentGraphEdgeCollectionData::make();
        }

        $definitions = $this->definitions($references);
        $requirements = $this->requirements($model, $definitions);
        $definitionKeys = $this->definitionKeys($definitions);
        $source = ContentGraphNodeData::fromModel($model);
        $siteId = $this->siteId($model);

        /** @var array<string, array{type: class-string<Model>, id: int, strength: ContentGraphEdgeStrength, properties: array<string, true>}> $targets */
        $targets = [];

        foreach ($references as [$definitionId, $targetType, $targetId]) {
            if ($model instanceof $targetType && $targetId === (int) $model->getKey()) {
                continue;
            }

            $identity = $targetType . '|' . $targetId;
            $strength = ($requirements[$definitionId] ?? PropertyRequirement::None)->atLeast(PropertyRequirement::Publish)
                ? ContentGraphEdgeStrength::Strong
                : ContentGraphEdgeStrength::Weak;

            $targets[$identity] ??= ['type' => $targetType, 'id' => $targetId, 'strength' => $strength, 'properties' => []];

            if ($strength === ContentGraphEdgeStrength::Strong) {
                $targets[$identity]['strength'] = $strength;
            }

            $targets[$identity]['properties'][$definitionKeys[$definitionId] ?? (string) $definitionId] = true;
        }

        ksort($targets);

        $edges = [];

        foreach ($targets as $target) {
            $properties = array_keys($target['properties']);
            sort($properties);

            $edges[] = new ContentGraphEdgeData(
                source: $source,
                target: ContentGraphNodeData::fromModelIdentity($target['type'], $target['id']),
                kind: ContentGraphEdgeKind::ReferencedByProperty,
                strength: $target['strength'],
                sourcePackage: self::SOURCE_PACKAGE,
                siteId: $siteId,
                metadata: ['properties' => $properties],
            );
        }

        return ContentGraphEdgeCollectionData::make($edges);
    }

    /**
     * Whether the owner still holds a property value pointing at the target. Value rows can vanish
     * without model events (database cascades, bulk deletes), so a stored edge is confirmed here
     * before it is allowed to retain or describe a dependency.
     *
     * @param  class-string<Model>  $targetType
     */
    public function stillReferences(int $ownerId, string $targetType, int $targetId): bool
    {
        $columns = array_keys(array_filter(
            $this->referenceColumns(),
            static fn (string $referencedType): bool => is_a($targetType, $referencedType, true),
        ));

        if ($columns === []) {
            return false;
        }

        $valueModel = $this->valueModel();

        return $valueModel::query()
            ->where($this->ownerColumn(), $ownerId)
            ->where(function (Builder $query) use ($columns, $targetId): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, $targetId);
                }
            })
            ->exists();
    }

    /**
     * The requirement each referenced definition carries for this owner.
     *
     * @param  array<int, PropertyDefinition>  $definitions
     * @return array<int, PropertyRequirement>
     */
    protected function requirements(Model $owner, array $definitions): array
    {
        return array_map(
            static fn (PropertyDefinition $definition): PropertyRequirement => $definition->requirement,
            $definitions,
        );
    }

    protected function positiveKey(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $key = (int) $value;

        return $key > 0 ? $key : null;
    }

    /**
     * Every non-empty reference column on the owner's property values.
     *
     * @return list<array{0: int, 1: class-string<Model>, 2: int}> definition id, target model, target key
     */
    private function references(Model $owner): array
    {
        $columns = $this->referenceColumns();
        $valueModel = $this->valueModel();

        $values = $valueModel::query()
            ->where($this->ownerColumn(), $owner->getKey())
            ->where(function (Builder $query) use ($columns): void {
                foreach (array_keys($columns) as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->get(['property_definition_id', ...array_keys($columns)]);

        $references = [];

        foreach ($values as $value) {
            $definitionId = $this->positiveKey($value->getAttribute('property_definition_id'));

            if ($definitionId === null) {
                continue;
            }

            foreach ($columns as $column => $targetType) {
                $targetKey = $this->positiveKey($value->getAttribute($column));

                if ($targetKey !== null) {
                    $references[] = [$definitionId, $targetType, $targetKey];
                }
            }
        }

        return $references;
    }

    /**
     * @param  list<array{0: int, 1: class-string<Model>, 2: int}>  $references
     * @return array<int, PropertyDefinition>
     */
    private function definitions(array $references): array
    {
        $definitionIds = array_values(array_unique(array_column($references, 0)));

        /** @var array<int, PropertyDefinition> $definitions */
        $definitions = PropertyDefinition::query()
            ->with('propertySet')
            ->whereKey($definitionIds)
            ->get()
            ->keyBy('id')
            ->all();

        return $definitions;
    }

    /**
     * @param  array<int, PropertyDefinition>  $definitions
     * @return array<int, string>
     */
    private function definitionKeys(array $definitions): array
    {
        $keys = [];

        foreach ($definitions as $definitionId => $definition) {
            $keys[$definitionId] = $definition->propertySet instanceof PropertySet
                ? $definition->qualifiedKey()
                : $definition->key;
        }

        return $keys;
    }
}
