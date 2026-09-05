<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\PromoteBlueprintFieldAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Exceptions\PropertyValueValidationException;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;

function promotedNameDefinition(Page $page): PropertyDefinition
{
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create(['key' => 'test.promotion']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'displayName',
        'type' => PropertyType::Text,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);
    PromoteBlueprintFieldAction::run($blueprint, $definition, 'name');

    return $definition;
}

it('flows a field edit to the promoted property on page save', function (): void {
    $page = Page::factory()->create(['name' => 'Original name']);
    $definition = promotedNameDefinition($page);

    $page->name = 'Updated name';
    $page->save();

    $value = PagePropertyValue::query()
        ->where('page_id', $page->id)
        ->where('property_definition_id', $definition->id)
        ->first();

    expect($value?->value_text)->toBe('Updated name');
});

it('rejects a direct write to a promoted property', function (): void {
    $page = Page::factory()->create();
    $definition = promotedNameDefinition($page);

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: $definition->key, type: PropertyType::Text, value: 'Direct write'),
    ]))->toThrow(PropertyValueValidationException::class);
});

it('stops syncing after unpromoting but keeps the last synced value', function (): void {
    $page = Page::factory()->create(['name' => 'First synced name']);
    $definition = promotedNameDefinition($page);

    $page->name = 'First synced name';
    $page->save();

    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    PromoteBlueprintFieldAction::run($blueprint, $definition, null);

    $page->name = 'Changed after unpromotion';
    $page->save();

    $value = PagePropertyValue::query()
        ->where('page_id', $page->id)
        ->where('property_definition_id', $definition->id)
        ->first();

    expect($value)->not->toBeNull()
        ->and($value?->value_text)->toBe('First synced name');

    // And the property is writable directly again now that it's unpromoted.
    SetPagePropertyValuesAction::run($page->fresh(), [
        new PropertyValueData(propertyKey: $definition->key, type: PropertyType::Text, value: 'Now direct'),
    ]);

    expect($value?->fresh()?->value_text)->toBe('Now direct');
});
