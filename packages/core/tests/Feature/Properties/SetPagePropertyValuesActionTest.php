<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\EventSourcing\Events\PageRevisionRecorded;
use Capell\Core\Exceptions\PropertyValueValidationException;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;

function attachProductSetToPage(Page $page): PropertyDefinition
{
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create(['key' => 'test.product']);
    $price = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'price',
        'type' => PropertyType::Money,
        'requirement' => PropertyRequirement::Contract,
        'locked' => true,
    ]);
    BlueprintPropertySet::factory()->create([
        'blueprint_id' => $blueprint->id,
        'property_set_id' => $set->id,
    ]);

    return $price;
}

it('writes a valid property value and records a page revision', function (): void {
    $page = Page::factory()->create();
    Translation::factory()->translatable($page)->language(Language::factory()->create())->create();
    $price = attachProductSetToPage($page);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'price', type: PropertyType::Money, value: 49.99, currency: 'GBP'),
    ]);

    expect(PagePropertyValue::query()->where('page_id', $page->id)->where('property_definition_id', $price->id)->first()?->value_number)
        ->toEqual('49.990000');

    $revisionEvents = DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRevisionRecorded::class)
        ->count();

    expect($revisionEvents)->toBeGreaterThanOrEqual(1);
});

it('throws a typed exception when the value type does not match the definition', function (): void {
    $page = Page::factory()->create();
    attachProductSetToPage($page);

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'price', type: PropertyType::Text, value: 'not-a-number'),
    ]))->toThrow(PropertyValueValidationException::class);
});

it("throws when a property is not attached to the page's blueprint", function (): void {
    $page = Page::factory()->create();

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'price', type: PropertyType::Money, value: 10, currency: 'GBP'),
    ]))->toThrow(PropertyValueValidationException::class);
});

it('rejects a localised value with no translation id', function (): void {
    $page = Page::factory()->create();
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'headline',
        'type' => PropertyType::Text,
        'localised' => true,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);

    expect(fn (): mixed => SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'headline', type: PropertyType::Text, value: 'Hello'),
    ]))->toThrow(PropertyValueValidationException::class);
});

it("clamps a locked definition's requirement floor so an override cannot lower it", function (): void {
    $page = Page::factory()->create();
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'sku',
        'type' => PropertyType::Text,
        'requirement' => PropertyRequirement::Publish,
        'locked' => true,
    ]);
    BlueprintPropertySet::factory()->create([
        'blueprint_id' => $blueprint->id,
        'property_set_id' => $set->id,
        'overrides' => ['sku' => ['requirement' => 'none']],
    ]);

    $effective = ResolveEffectiveDefinitionsAction::run($page);
    $sku = $effective->first(fn ($d): bool => $d->key === 'sku');

    expect($sku->requirement)->toBe(PropertyRequirement::Publish);
});

it('allows an override to raise requirement on a non-locked definition', function (): void {
    $page = Page::factory()->create();
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create();
    PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'notes',
        'type' => PropertyType::Text,
        'requirement' => PropertyRequirement::None,
        'locked' => false,
    ]);
    BlueprintPropertySet::factory()->create([
        'blueprint_id' => $blueprint->id,
        'property_set_id' => $set->id,
        'overrides' => ['notes' => ['requirement' => 'publish']],
    ]);

    $effective = ResolveEffectiveDefinitionsAction::run($page);
    $notes = $effective->first(fn ($d): bool => $d->key === 'notes');

    expect($notes->requirement)->toBe(PropertyRequirement::Publish);
});
