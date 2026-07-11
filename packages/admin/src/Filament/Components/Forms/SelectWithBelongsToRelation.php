<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Support\Filament\RawState;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;
use RuntimeException;
use Znck\Eloquent\Relations\BelongsToThrough;

class SelectWithBelongsToRelation extends Select
{
    /**
     * @return BelongsTo<Model, Model>|BelongsToMany<Model, Model>|BelongsToThrough<Model, Model>|HasOneOrMany<Model, Model, mixed>|HasOneOrManyThrough<Model, Model, Model, mixed>|MorphTo<Model, Model>|null
     */
    #[Override]
    public function getRelationship(): BelongsTo|BelongsToMany|BelongsToThrough|HasOneOrMany|HasOneOrManyThrough|MorphTo|null
    {
        $relationship = parent::getRelationship();

        if ($relationship === null) {
            return null;
        }

        // If it's not a MorphTo, we just return what the parent resolved.
        if (! $relationship instanceof MorphTo) {
            return $relationship;
        }

        // MorphTo specific tidy logic: resolve correct related model when morph type attribute is already set.
        // This prevent MorphTo related model being a generic placeholder using the parent model.
        $record = $this->getModelInstance();
        $morphBlueprintColumn = $relationship->getMorphType();
        $rawState = RawState::array($this->getContainer()->getRawState());
        $morphTypeValue = $rawState[$morphBlueprintColumn] ?? $record?->getAttribute($morphBlueprintColumn);

        // If morph type is blank (unsaved or not yet chosen) keep eager MorphTo as-is.
        if (blank($morphTypeValue)) {
            return $relationship; // Eager placeholder; parent behavior retained.
        }

        $expectedClass = Model::getActualClassNameForMorph($morphTypeValue);
        $currentRelatedClass = $relationship->getRelated()::class;

        // If already correct, return.
        if ($currentRelatedClass === $expectedClass) {
            return $relationship;
        }

        // Re-instantiate relation with temporarily set morph type so Laravel builds the proper related instance.
        if ($record instanceof Model) {
            $originalType = $record->getAttribute($morphBlueprintColumn);
            $record->setAttribute($morphBlueprintColumn, $morphTypeValue);
            $relationship = $record->{$this->getRelationshipName()}();
            // Restore original (null) to avoid side-effects if it was previously unset.
            if ($originalType === null) {
                $record->setAttribute($morphBlueprintColumn, $originalType);
            }
        }

        return $relationship;
    }

    // Must be called after `relation`
    public function savesBelongsToRelation(): static
    {
        return $this->saveRelationshipsUsing(
            static function (SelectWithBelongsToRelation $component, Model $record, null|Model|int $state): void {
                $relationship = $component->getRelationship();
                throw_unless($relationship instanceof BelongsTo, RuntimeException::class, 'SelectWithBelongsToRelation only supports belongs to relationships.');

                if (! $state instanceof Model) {
                    $selectedRecord = $component->getSelectedRecord();
                    if ($selectedRecord?->getKey() !== $state) {
                        $component->clearCachedSelectedRecord();
                        $selectedRecord = $component->getSelectedRecord();
                    }

                    if (! $selectedRecord instanceof Model) {
                        return;
                    }

                    $state = $selectedRecord;
                }

                // Removed unreachable block for clarity.
                $relationship->associate($state);
                if ($record->wasRecentlyCreated) {
                    $record->save();
                }
            },
        );
    }

    public function clearCachedSelectedRecord(): self
    {
        $this->cachedSelectedRecord = null;

        return $this;
    }
}
