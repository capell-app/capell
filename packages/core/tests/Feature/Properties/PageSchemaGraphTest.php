<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\BuildPageSchemaGraphAction;
use Capell\Core\Data\Properties\AgentPropertyEntryData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;

it('omits the graph when no public properties resolve', function (): void {
    expect(BuildPageSchemaGraphAction::run(Page::factory()->create()))->toBeNull();
});

it('projects product money into an offer and preserves repeated semantic values without internal keys', function (): void {
    $page = agentSchemaPageWithValues([
        new AgentPropertyEntryData('commerce.product.price', 'schema:price', PropertyType::Money, 19.95, 'GBP'),
        new AgentPropertyEntryData('commerce.product.weight', 'schema:weight', PropertyType::Dimension, 2, unit: 'kg'),
        new AgentPropertyEntryData('commerce.product.image', 'schema:image', PropertyType::Url, 'https://example.test/one.jpg'),
        new AgentPropertyEntryData('commerce.product.image', 'schema:image', PropertyType::Url, 'https://example.test/two.jpg', position: 1),
        new AgentPropertyEntryData('private.blueprint.internal', null, PropertyType::Text, 'Do not expose this'),
    ]);

    $graph = BuildPageSchemaGraphAction::run($page);
    $node = $graph->nodes[0];

    expect($node['@type'])->toBe('Product')
        ->and($node['offers']['@type'])->toBe('Offer')
        ->and($node['offers']['priceSpecification']['price'])->toBe(19.95)
        ->and($node['offers']['priceSpecification']['priceCurrency'])->toBe('GBP')
        ->and($node['weight']['unitCode'])->toBe('KGM')
        ->and($node['image'])->toBe(['https://example.test/one.jpg', 'https://example.test/two.jpg'])
        ->and($graph->toJsonLdScript())->not->toContain('commerce.product', 'private.blueprint', 'Do not expose this', 'property_definition_id');
});

it('escapes author content so JSON LD cannot terminate its script element', function (): void {
    $page = agentSchemaPageWithValues([
        new AgentPropertyEntryData('content.article.author', 'schema:author', PropertyType::Text, '</script><script>alert(1)</script>'),
    ]);

    $script = BuildPageSchemaGraphAction::run($page)->toJsonLdScript();

    expect(substr_count((string) $script, '</script>'))->toBe(1)
        ->and($script)->not->toContain('<script>alert');
});

/** @param list<AgentPropertyEntryData> $entries */
function agentSchemaPageWithValues(array $entries): Page
{
    $page = Page::factory()->withTranslations()->create(['visible_from' => now()->subDay()]);
    $set = PropertySet::factory()->create(['key' => 'commerce.product.fixture']);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    foreach ($entries as $entry) {
        $key = substr($entry->qualifiedKey, strrpos($entry->qualifiedKey, '.') + 1);
        $definition = $set->definitions()->where('key', $key)->first()
            ?? PropertyDefinition::factory()->create([
                'property_set_id' => $set->id, 'key' => $key, 'semantic' => $entry->semantic,
                'type' => $entry->type, 'agent_visible' => true, 'multiple' => true,
            ]);
        PagePropertyValue::factory()->create([
            'site_id' => $page->site_id, 'page_id' => $page->id, 'property_definition_id' => $definition->id,
            'translation_id' => null, 'position' => $entry->position,
            'value_text' => $entry->type->isNumeric() ? null : $entry->value,
            'value_number' => $entry->type->isNumeric() ? $entry->value : null,
            'currency' => $entry->currency, 'unit' => $entry->unit,
        ]);
    }

    return $page;
}

it('omits schema graphs for inaccessible and disabled blueprints', function (string $reason): void {
    $page = agentSchemaPageWithValues([
        new AgentPropertyEntryData('content.article.author', 'schema:author', PropertyType::Text, 'Private author'),
    ]);
    $page->blueprint->update($reason === 'private' ? ['meta' => ['accessible' => false]] : ['status' => false]);

    expect(BuildPageSchemaGraphAction::run($page))->toBeNull();
})->with(['private', 'disabled']);
