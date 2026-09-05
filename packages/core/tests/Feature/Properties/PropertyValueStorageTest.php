<?php

declare(strict_types=1);

use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\QueryException;

it('round-trips a money value with decimal precision preserved', function (): void {
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Money]);
    $page = Page::factory()->create();

    $value = PagePropertyValue::factory()->create([
        'site_id' => $page->site_id,
        'page_id' => $page->id,
        'property_definition_id' => $definition->id,
        'value_text' => null,
        'value_number' => '19.99',
        'currency' => 'GBP',
    ]);

    $data = PropertyValueData::fromPageValue($value->fresh(), $definition->key, PropertyType::Money);

    expect($data->value)->toBe(19.99)
        ->and($data->currency)->toBe('GBP');
});

it('orders multiple rows for the same definition by position', function (): void {
    $definition = PropertyDefinition::factory()->create(['type' => PropertyType::Text, 'multiple' => true]);
    $page = Page::factory()->create();

    PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $definition->id,
        'position' => 1, 'value_text' => 'second',
    ]);
    PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $definition->id,
        'position' => 0, 'value_text' => 'first',
    ]);

    $ordered = PagePropertyValue::query()
        ->where('page_id', $page->id)
        ->where('property_definition_id', $definition->id)
        ->orderBy('position')
        ->pluck('value_text');

    expect($ordered->all())->toBe(['first', 'second']);
});

it("never returns another site's property values from forSite (cross-site regression)", function (): void {
    $definition = PropertyDefinition::factory()->create();
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $pageA = Page::factory()->create(['site_id' => $siteA->id]);
    $pageB = Page::factory()->create(['site_id' => $siteB->id]);

    PagePropertyValue::factory()->create([
        'site_id' => $siteA->id, 'page_id' => $pageA->id, 'property_definition_id' => $definition->id,
        'value_text' => 'site-a-secret',
    ]);
    PagePropertyValue::factory()->create([
        'site_id' => $siteB->id, 'page_id' => $pageB->id, 'property_definition_id' => $definition->id,
        'value_text' => 'site-b-value',
    ]);

    $siteBValues = PagePropertyValue::query()->forSite($siteB->id)->pluck('value_text');

    expect($siteBValues->all())->toBe(['site-b-value'])
        ->and($siteBValues->all())->not->toContain('site-a-secret');
});

it('enforces the identity unique constraint per (page, definition, translation, position) for localised rows', function (): void {
    // The unique index includes the nullable translation_id column. SQL
    // treats NULL as distinct from NULL in a unique key on every supported
    // driver (MySQL, Postgres, SQLite), so the DB constraint only actually
    // fires for the localised case (translation_id NOT NULL) exercised here.
    // The non-localised (translation_id NULL) case is deduplicated at the
    // application layer by SetPagePropertyValuesAction's update-or-create
    // identity resolution (Task 5), not by this index.
    $definition = PropertyDefinition::factory()->create();
    $page = Page::factory()->create();
    $translation = Translation::factory()->translatable($page)->create();

    PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $definition->id,
        'translation_id' => $translation->id, 'position' => 0,
    ]);

    expect(fn () => PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $definition->id,
        'translation_id' => $translation->id, 'position' => 0,
    ]))->toThrow(QueryException::class);
});

it('round-trips a boolean and a datetime value through PropertyValueData', function (): void {
    $boolDefinition = PropertyDefinition::factory()->create(['type' => PropertyType::Boolean]);
    $dateDefinition = PropertyDefinition::factory()->create(['type' => PropertyType::DateTime]);
    $page = Page::factory()->create();

    $boolValue = PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $boolDefinition->id,
        'value_text' => null, 'value_boolean' => true,
    ]);
    $dateValue = PagePropertyValue::factory()->create([
        'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $dateDefinition->id,
        'value_text' => null, 'value_datetime' => '2026-12-25 10:00:00',
    ]);

    $boolData = PropertyValueData::fromPageValue($boolValue->fresh(), $boolDefinition->key, PropertyType::Boolean);
    $dateData = PropertyValueData::fromPageValue($dateValue->fresh(), $dateDefinition->key, PropertyType::DateTime);

    expect($boolData->value)->toBeTrue()
        ->and($dateData->value)->toBeInstanceOf(DateTimeInterface::class);
});
