<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Support\Properties\BuiltInPropertySets;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Idempotently upserts Core's built-in property sets (by default
 * {@see BuiltInPropertySets::all()}) into the database. Safe to run on every
 * install and every upgrade.
 *
 * Lifecycle safety rule: a property set that already exists is being
 * *version-bumped*, not freshly installed. On a version bump, any definition
 * whose canonical requirement is {@see PropertyRequirement::Publish} is
 * clamped to {@see PropertyRequirement::Contract} — a newly-tightened or
 * newly-added required property must never retroactively hard-gate publish
 * for content that was already valid. Only a genuinely first-ever install of
 * the set applies the literal declared requirement.
 */
final class SyncBuiltInPropertySetsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, array{name: string, definitions: list<array<string, mixed>>}>|null  $source
     */
    public function handle(?array $source = null): void
    {
        $source ??= BuiltInPropertySets::all();

        foreach ($source as $setKey => $setData) {
            $isNewSet = ! PropertySet::query()->where('key', $setKey)->exists();

            $set = PropertySet::query()->updateOrCreate(
                ['key' => $setKey],
                [
                    'name' => $setData['name'],
                    'owner_package' => BuiltInPropertySets::OWNER_PACKAGE,
                ],
            );

            foreach (array_values($setData['definitions']) as $position => $definitionData) {
                /** @var PropertyRequirement $requirement */
                $requirement = $definitionData['requirement'];

                if (! $isNewSet && $requirement === PropertyRequirement::Publish) {
                    $requirement = PropertyRequirement::Contract;
                }

                PropertyDefinition::query()->updateOrCreate(
                    [
                        'property_set_id' => $set->id,
                        'key' => $definitionData['key'],
                    ],
                    [
                        'type' => $definitionData['type'],
                        'semantic' => $definitionData['semantic'],
                        'requirement' => $requirement,
                        'agent_visible' => true,
                        'localised' => false,
                        'multiple' => false,
                        'locked' => $definitionData['locked'],
                        'description' => $definitionData['description'],
                        'unit_config' => $definitionData['unit_config'],
                        'position' => $position,
                    ],
                );
            }
        }
    }
}
