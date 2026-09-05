<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\ResolveAgentPropertyValuesAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Properties\AgentPropertyBagData;
use Capell\Core\Data\Properties\AgentPropertyEntryData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;

function attachedProductDefinition(Page $page, array $overrides = []): PropertyDefinition
{
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create(['key' => 'test.resolve']);
    $definition = PropertyDefinition::factory()->create(array_merge([
        'property_set_id' => $set->id,
        'key' => 'brandName',
        'type' => PropertyType::Text,
        'semantic' => 'schema:brand',
    ], $overrides));
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);

    return $definition;
}

it('returns an empty bag for a page that is not currently published (draft values never appear)', function (): void {
    $page = Page::factory()->create(['visible_from' => PublishSentinel::draftValue(CarbonImmutable::now())]);
    $definition = attachedProductDefinition($page);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: $definition->key, type: PropertyType::Text, value: 'Acme'),
    ]);

    $bag = ResolveAgentPropertyValuesAction::run($page->fresh());

    expect($bag->isEmpty())->toBeTrue();
});

it('lets a page-level value win over a term-carried value for the same property', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $definition = attachedProductDefinition($page);

    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $term = Term::factory()->for($taxonomy)->create();
    TermPropertyValue::factory()->create([
        'term_id' => $term->id,
        'property_definition_id' => $definition->id,
        'value_text' => 'From term',
    ]);
    $page->terms()->attach($term->id, ['position' => 0]);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: $definition->key, type: PropertyType::Text, value: 'From page'),
    ]);

    $bag = ResolveAgentPropertyValuesAction::run($page->fresh());

    expect($bag->entries)->toHaveCount(1)
        ->and($bag->entries[0]->value)->toBe('From page');
});

it('inherits a term-carried value onto the page when the page has no value of its own', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $definition = attachedProductDefinition($page);

    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $term = Term::factory()->for($taxonomy)->create();
    TermPropertyValue::factory()->create([
        'term_id' => $term->id,
        'property_definition_id' => $definition->id,
        'value_text' => 'Inherited brand',
    ]);
    $page->terms()->attach($term->id, ['position' => 0]);

    $bag = ResolveAgentPropertyValuesAction::run($page->fresh());

    expect($bag->entries)->toHaveCount(1)
        ->and($bag->entries[0]->value)->toBe('Inherited brand')
        ->and($bag->entries[0]->semantic)->toBe('schema:brand');
});

it('resolves term collisions deterministically by taxonomy position then assignment position', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $definition = attachedProductDefinition($page);

    $laterTaxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id, 'position' => 1]);
    $earlierTaxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id, 'position' => 0]);

    $termFromLaterTaxonomy = Term::factory()->for($laterTaxonomy)->create();
    TermPropertyValue::factory()->create([
        'term_id' => $termFromLaterTaxonomy->id, 'property_definition_id' => $definition->id, 'value_text' => 'From later taxonomy',
    ]);

    $termFromEarlierTaxonomy = Term::factory()->for($earlierTaxonomy)->create();
    TermPropertyValue::factory()->create([
        'term_id' => $termFromEarlierTaxonomy->id, 'property_definition_id' => $definition->id, 'value_text' => 'From earlier taxonomy',
    ]);

    // Attach the later-taxonomy term FIRST (lower pivot position) to prove
    // taxonomy position wins over assignment order.
    $page->terms()->attach($termFromLaterTaxonomy->id, ['position' => 0]);
    $page->terms()->attach($termFromEarlierTaxonomy->id, ['position' => 1]);

    $bag = ResolveAgentPropertyValuesAction::run($page->fresh());

    expect($bag->entries)->toHaveCount(1)
        ->and($bag->entries[0]->value)->toBe('From earlier taxonomy');
});

it('never exposes a hidden (agent_visible false) property', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create(['key' => 'test.hidden']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'internalNote',
        'type' => PropertyType::Text,
        'agent_visible' => false,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'internalNote', type: PropertyType::Text, value: 'secret'),
    ]);

    $bag = ResolveAgentPropertyValuesAction::run($page->fresh());

    expect($bag->isEmpty())->toBeTrue();
});

it('resolves the value for the requested language when a localised value exists for it', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $language = Language::factory()->create();
    Translation::factory()->translatable($page)->language($language)->create();

    $definition = attachedProductDefinition($page, ['key' => 'tagline', 'localised' => true, 'semantic' => null]);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'tagline', type: PropertyType::Text, value: 'English tagline', translationId: $page->translations()->first()->id),
    ]);

    $bag = ResolveAgentPropertyValuesAction::run($page->fresh(), $language);

    expect($bag->entries)->toHaveCount(1)
        ->and($bag->entries[0]->value)->toBe('English tagline');
});

it('resolves no value for a language with no localised row of its own (no cross-language leakage)', function (): void {
    // SetPagePropertyValuesAction requires an explicit translation for every
    // localised value (CAP-0460 Task 5), so a page-level "default" row can
    // never exist for a localised property under this write path — the
    // fallback branch in ResolveAgentPropertyValuesAction exists for forward
    // compatibility but has nothing to fall back to today. A language with
    // no row of its own must resolve empty, never another language's value.
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $english = Language::factory()->create();
    $french = Language::factory()->create();
    Translation::factory()->translatable($page)->language($english)->create();
    Translation::factory()->translatable($page)->language($french)->create();

    attachedProductDefinition($page, ['key' => 'tagline', 'localised' => true, 'semantic' => null]);

    $englishTranslationId = $page->translations()->where('language_id', $english->id)->value('id');

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'tagline', type: PropertyType::Text, value: 'English only', translationId: $englishTranslationId),
    ]);

    $frenchBag = ResolveAgentPropertyValuesAction::run($page->fresh(), $french);

    expect($frenchBag->isEmpty())->toBeTrue();
});

it('projects money and dimension entries into schema.org-shaped values', function (): void {
    $bag = new AgentPropertyBagData(entries: [
        new AgentPropertyEntryData(
            qualifiedKey: 'commerce.product.price',
            semantic: 'schema:price',
            type: PropertyType::Money,
            value: 49.99,
            currency: 'GBP',
        ),
        new AgentPropertyEntryData(
            qualifiedKey: 'commerce.product.weight',
            semantic: 'schema:weight',
            type: PropertyType::Dimension,
            value: 1.5,
            unit: 'kg',
        ),
        new AgentPropertyEntryData(
            qualifiedKey: 'test.custom.note',
            semantic: null,
            type: PropertyType::Text,
            value: 'unmapped',
        ),
    ]);

    $properties = $bag->toSchemaOrgProperties();

    expect($properties['price'])->toBe(['@type' => 'PriceSpecification', 'price' => 49.99, 'priceCurrency' => 'GBP'])
        ->and($properties['weight'])->toBe(['@type' => 'QuantitativeValue', 'value' => 1.5, 'unitCode' => 'kg'])
        ->and($properties['capell:test.custom.note'])->toBe('unmapped');
});

it('does not inherit term values assigned from a different site', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $definition = attachedProductDefinition($page);
    $otherPage = Page::factory()->create();
    $taxonomy = Taxonomy::factory()->create(['site_id' => $otherPage->site_id]);
    $term = Term::factory()->for($taxonomy)->create();
    TermPropertyValue::factory()->create([
        'term_id' => $term->id,
        'property_definition_id' => $definition->id,
        'value_text' => 'Other site private value',
    ]);
    $page->terms()->attach($term->id, ['position' => 0]);

    expect(ResolveAgentPropertyValuesAction::run($page)->isEmpty())->toBeTrue();
});

it('breaks equal taxonomy and assignment positions by term identity', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $definition = attachedProductDefinition($page);
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $first = Term::factory()->for($taxonomy)->create();
    $second = Term::factory()->for($taxonomy)->create();
    foreach ([$first, $second] as $term) {
        TermPropertyValue::factory()->create([
            'term_id' => $term->id, 'property_definition_id' => $definition->id,
            'value_text' => $term === $first ? 'First term' : 'Second term',
        ]);
    }

    $page->terms()->attach($second->id, ['position' => 0]);
    $page->terms()->attach($first->id, ['position' => 0]);

    expect(ResolveAgentPropertyValuesAction::run($page)->entries[0]->value)->toBe('First term');
});

it('round trips reference identifiers through the typed page value writer', function (): void {
    $page = Page::factory()->published()->create();
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create(['key' => 'test.reference-round-trip']);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);
    $definitions = collect([
        ['key' => 'termRef', 'type' => PropertyType::TermReference],
        ['key' => 'entryRef', 'type' => PropertyType::EntryReference],
        ['key' => 'mediaRef', 'type' => PropertyType::Media],
    ])->map(fn (array $data): PropertyDefinition => PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => $data['key'],
        'type' => $data['type'],
        'agent_visible' => true,
    ]));
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $term = Term::factory()->for($taxonomy)->create();
    $target = Page::factory()->site($page->site)->published()->create();
    $media = Media::factory()->model($page)->create();

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData('termRef', PropertyType::TermReference, $term->id),
        new PropertyValueData('entryRef', PropertyType::EntryReference, $target->id),
        new PropertyValueData('mediaRef', PropertyType::Media, $media->id),
    ]);

    $entries = ResolveAgentPropertyValuesAction::run($page->fresh())->entries;

    expect($entries)->toHaveCount(3)
        ->and(collect($entries)->keyBy('qualifiedKey')['test.reference-round-trip.termRef']->referenceId)->toBe($term->id)
        ->and(collect($entries)->keyBy('qualifiedKey')['test.reference-round-trip.entryRef']->referenceId)->toBe($target->id)
        ->and(collect($entries)->keyBy('qualifiedKey')['test.reference-round-trip.mediaRef']->referenceId)->toBe($media->id);
});

it('does not expose a page property row recorded against another site', function (): void {
    $page = Page::factory()->published()->create();
    $definition = attachedProductDefinition($page);
    $otherPage = Page::factory()->create();
    PagePropertyValue::factory()->create([
        'page_id' => $page->id,
        'site_id' => $otherPage->site_id,
        'property_definition_id' => $definition->id,
        'value_text' => 'Foreign site value',
    ]);

    expect(ResolveAgentPropertyValuesAction::run($page)->isEmpty())->toBeTrue();
});

it('emits only the first row for a non-multiple property', function (): void {
    $page = Page::factory()->published()->create();
    $definition = attachedProductDefinition($page);

    PagePropertyValue::factory()->create([
        'site_id' => $page->site_id,
        'page_id' => $page->id,
        'property_definition_id' => $definition->id,
        'position' => 1,
        'value_text' => 'Later corrupt row',
    ]);
    PagePropertyValue::factory()->create([
        'site_id' => $page->site_id,
        'page_id' => $page->id,
        'property_definition_id' => $definition->id,
        'position' => 0,
        'value_text' => 'First row',
    ]);

    $entries = ResolveAgentPropertyValuesAction::run($page)->entries;

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->value)->toBe('First row');
});

it('inherits every ordered value from the winning term for a multiple property', function (): void {
    $page = Page::factory()->published()->create();
    $definition = attachedProductDefinition($page, ['key' => 'brands', 'multiple' => true]);
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id]);
    $winner = Term::factory()->for($taxonomy)->create();
    $loser = Term::factory()->for($taxonomy)->create();

    TermPropertyValue::factory()->create([
        'term_id' => $winner->id,
        'property_definition_id' => $definition->id,
        'position' => 1,
        'value_text' => 'Winner second',
    ]);
    TermPropertyValue::factory()->create([
        'term_id' => $winner->id,
        'property_definition_id' => $definition->id,
        'position' => 0,
        'value_text' => 'Winner first',
    ]);
    TermPropertyValue::factory()->create([
        'term_id' => $loser->id,
        'property_definition_id' => $definition->id,
        'position' => 0,
        'value_text' => 'Loser value',
    ]);
    $page->terms()->attach($winner->id, ['position' => 0]);
    $page->terms()->attach($loser->id, ['position' => 1]);

    $entries = ResolveAgentPropertyValuesAction::run($page)->entries;

    expect($entries)->toHaveCount(2)
        ->and(collect($entries)->pluck('value')->all())->toBe(['Winner first', 'Winner second']);
});
