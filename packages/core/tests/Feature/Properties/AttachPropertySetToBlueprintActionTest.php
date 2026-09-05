<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\AttachPropertySetToBlueprintAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\PropertySet;

it('attaches a property set to a blueprint', function (): void {
    $blueprint = Blueprint::factory()->create();
    $set = PropertySet::factory()->create();

    $attachment = AttachPropertySetToBlueprintAction::run($blueprint, $set);

    expect($attachment)->toBeInstanceOf(BlueprintPropertySet::class)
        ->and(BlueprintPropertySet::query()->where('blueprint_id', $blueprint->id)->where('property_set_id', $set->id)->exists())->toBeTrue();
});

it('is idempotent: attaching again updates overrides instead of duplicating', function (): void {
    $blueprint = Blueprint::factory()->create();
    $set = PropertySet::factory()->create();

    AttachPropertySetToBlueprintAction::run($blueprint, $set);
    AttachPropertySetToBlueprintAction::run($blueprint, $set, ['sku' => ['requirement' => 'contract']]);

    expect(BlueprintPropertySet::query()->where('blueprint_id', $blueprint->id)->where('property_set_id', $set->id)->count())->toBe(1)
        ->and(BlueprintPropertySet::query()->where('blueprint_id', $blueprint->id)->first()?->overrides)
        ->toBe(['sku' => ['requirement' => 'contract']]);
});
