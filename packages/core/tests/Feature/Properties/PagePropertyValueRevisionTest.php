<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;

function attachTextPropertyToPage(Page $page, string $key = 'headline'): void
{
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create(['key' => 'test.article']);
    PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => $key,
        'type' => PropertyType::Text,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);
}

it('captures property values as part of the page state snapshot', function (): void {
    $page = Page::factory()->create();
    attachTextPropertyToPage($page);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'headline', type: PropertyType::Text, value: 'Original headline'),
    ]);

    $serializer = resolve(PageStateSerializer::class);
    $captured = $serializer->capture($page->fresh());

    expect($captured['propertyValues'])->toHaveCount(1)
        ->and($captured['propertyValues'][0]['value_text'])->toBe('Original headline');
});

it('restores property values on rollback, event-silently', function (): void {
    $page = Page::factory()->create();
    attachTextPropertyToPage($page);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'headline', type: PropertyType::Text, value: 'Version one'),
    ]);

    $serializer = resolve(PageStateSerializer::class);
    $captured = $serializer->capture($page->fresh());

    SetPagePropertyValuesAction::run($page->fresh(), [
        new PropertyValueData(propertyKey: 'headline', type: PropertyType::Text, value: 'Version two'),
    ]);

    expect(PagePropertyValue::query()->where('page_id', $page->id)->first()?->value_text)->toBe('Version two');

    $serializer->restore($page->fresh(), $captured);

    expect(PagePropertyValue::query()->where('page_id', $page->id)->first()?->value_text)->toBe('Version one');
});
