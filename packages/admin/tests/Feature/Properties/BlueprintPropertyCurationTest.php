<?php

declare(strict_types=1);

use Capell\Admin\Enums\BlueprintCreationModeEnum;
use Capell\Admin\Filament\Resources\Blueprints\Pages\ManageBlueprints;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('type');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('attaches a property set to a page blueprint from the admin form', function (): void {
    $blueprint = Blueprint::factory()->page()->create();
    $set = PropertySet::factory()->create(['key' => 'test.curation', 'name' => 'Curation set']);
    PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'price',
        'type' => PropertyType::Money,
        'requirement' => PropertyRequirement::Contract,
        'locked' => true,
    ]);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction('edit', $blueprint, [
            'creation_mode' => BlueprintCreationModeEnum::Custom->value,
            'name' => $blueprint->name,
            'key' => $blueprint->key,
            'type' => BlueprintSubjectEnum::Page->value,
            'order' => $blueprint->order ?? 1,
            'default' => false,
            'status' => true,
            'blueprintPropertySets' => [
                [
                    'property_set_id' => $set->id,
                    'overrides' => null,
                ],
            ],
        ])
        ->assertHasNoFormErrors();

    expect(BlueprintPropertySet::query()
        ->where('blueprint_id', $blueprint->id)
        ->where('property_set_id', $set->id)
        ->exists())->toBeTrue();
});

it('rejects malformed JSON in the overrides field', function (): void {
    $blueprint = Blueprint::factory()->page()->create();
    $set = PropertySet::factory()->create(['key' => 'test.curation.invalid']);

    Livewire::test(ManageBlueprints::class)
        ->assertSuccessful()
        ->callTableAction('edit', $blueprint, [
            'creation_mode' => BlueprintCreationModeEnum::Custom->value,
            'name' => $blueprint->name,
            'key' => $blueprint->key,
            'type' => BlueprintSubjectEnum::Page->value,
            'order' => $blueprint->order ?? 1,
            'default' => false,
            'status' => true,
            'blueprintPropertySets' => [
                [
                    'property_set_id' => $set->id,
                    'overrides' => '{not valid json',
                ],
            ],
        ])
        ->assertHasFormErrors();
});
