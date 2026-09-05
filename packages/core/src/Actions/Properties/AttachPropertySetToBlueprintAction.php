<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\PropertySet;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Attaches a {@see PropertySet} to a {@see Blueprint}, or updates the
 * per-property overrides of an existing attachment. Idempotent: attaching an
 * already-attached set updates its overrides rather than duplicating the row
 * (the pivot table's unique pair constraint would reject a duplicate anyway).
 */
final class AttachPropertySetToBlueprintAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, array<string, mixed>>|null  $overrides
     */
    public function handle(Blueprint $blueprint, PropertySet $propertySet, ?array $overrides = null): BlueprintPropertySet
    {
        return BlueprintPropertySet::query()->updateOrCreate(
            [
                'blueprint_id' => $blueprint->id,
                'property_set_id' => $propertySet->id,
            ],
            [
                'overrides' => $overrides,
            ],
        );
    }
}
