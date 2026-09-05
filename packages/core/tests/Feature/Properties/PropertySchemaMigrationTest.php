<?php

declare(strict_types=1);

use Capell\Core\Enums\PropertyRequirement;
use Illuminate\Support\Facades\Schema;

it('creates the property set, definition and blueprint attachment tables', function (): void {
    expect(Schema::hasTable('property_sets'))->toBeTrue()
        ->and(Schema::hasColumns('property_sets', ['id', 'key', 'name', 'version', 'owner_package']))->toBeTrue()
        ->and(Schema::hasTable('property_definitions'))->toBeTrue()
        ->and(Schema::hasColumns('property_definitions', [
            'property_set_id', 'key', 'type', 'semantic', 'requirement',
            'agent_visible', 'localised', 'multiple', 'locked', 'description', 'unit_config', 'position',
        ]))->toBeTrue()
        ->and(Schema::hasTable('blueprint_property_sets'))->toBeTrue()
        ->and(Schema::hasColumns('blueprint_property_sets', ['blueprint_id', 'property_set_id', 'overrides']))->toBeTrue();
});

it('enforces requirement floors: publish is at least contract, none is not', function (): void {
    expect(PropertyRequirement::Publish->atLeast(PropertyRequirement::Contract))->toBeTrue()
        ->and(PropertyRequirement::None->atLeast(PropertyRequirement::Contract))->toBeFalse()
        ->and(PropertyRequirement::Contract->atLeast(PropertyRequirement::Contract))->toBeTrue()
        ->and(PropertyRequirement::None->clampedTo(PropertyRequirement::Contract))->toBe(PropertyRequirement::Contract)
        ->and(PropertyRequirement::Publish->clampedTo(PropertyRequirement::Contract))->toBe(PropertyRequirement::Publish);
});
