<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\BrowseAgentTaxonomiesAction;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('scopes taxonomy discovery and lookup to the requested site', function (): void {
    $site = Site::factory()->create();
    Taxonomy::factory()->create(['site_id' => $site->id, 'key' => 'brands']);
    Taxonomy::factory()->create(['key' => 'private-other-site']);

    $result = BrowseAgentTaxonomiesAction::run($site);
    expect($result->items())->toHaveCount(1)
        ->and($result->items()[0]['key'])->toBe('brands')
        ->and(array_keys($result->items()[0]))->toBe(['key', 'name', 'hierarchical']);

    expect(fn (): mixed => BrowseAgentTaxonomiesAction::run($site, 'private-other-site'))
        ->toThrow(ModelNotFoundException::class);
});

it('projects semantic term values and a same-taxonomy parent without internal identifiers', function (): void {
    $site = Site::factory()->create();
    $set = PropertySet::factory()->create();
    $taxonomy = Taxonomy::factory()->create(['site_id' => $site->id, 'key' => 'brands', 'property_set_id' => $set->id, 'hierarchical' => true]);
    $parent = Term::factory()->for($taxonomy)->create(['slug' => 'parent', 'position' => 0]);
    $term = Term::factory()->for($taxonomy)->create(['slug' => 'child', 'parent_id' => $parent->id, 'position' => 1]);
    foreach ([['name', true, 'Public name'], ['description', false, 'Hidden value']] as [$semantic, $visible, $value]) {
        $definition = PropertyDefinition::factory()->create([
            'property_set_id' => $set->id, 'key' => 'internal-' . $semantic,
            'semantic' => 'schema:' . $semantic, 'type' => PropertyType::Text, 'agent_visible' => $visible,
        ]);
        TermPropertyValue::factory()->create(['term_id' => $term->id, 'property_definition_id' => $definition->id, 'value_text' => $value]);
    }

    $items = BrowseAgentTaxonomiesAction::run($site, 'brands')->items();
    expect($items[1]['parent'])->toBe('parent')
        ->and((array) $items[1]['properties'])->toBe(['name' => 'Public name'])
        ->and(json_encode($items, JSON_THROW_ON_ERROR))->not->toContain('Hidden value', 'internal-', 'taxonomy_id', 'property_definition_id');
});

it('projects a same-site term reference and omits a foreign reference', function (): void {
    $site = Site::factory()->create();
    $set = PropertySet::factory()->create();
    $taxonomy = Taxonomy::factory()->create(['site_id' => $site->id, 'key' => 'brands', 'property_set_id' => $set->id]);
    $owner = Term::factory()->for($taxonomy)->create(['position' => 0]);
    $target = Term::factory()->for($taxonomy)->create(['name' => 'Public brand', 'position' => 1]);
    $foreign = Term::factory()->create(['name' => 'Foreign private brand']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'brand', 'semantic' => 'schema:brand',
        'type' => PropertyType::TermReference, 'agent_visible' => true, 'multiple' => true,
    ]);
    foreach ([$target, $foreign] as $position => $reference) {
        TermPropertyValue::factory()->create([
            'term_id' => $owner->id, 'property_definition_id' => $definition->id,
            'referenced_term_id' => $reference->id, 'position' => $position,
        ]);
    }

    $items = BrowseAgentTaxonomiesAction::run($site, 'brands')->items();
    expect((array) $items[0]['properties'])->toHaveKey('brand')
        ->and(json_encode($items[0]['properties'], JSON_THROW_ON_ERROR))->toContain('Public brand')
        ->not->toContain('Foreign private brand', 'referenced_term_id', 'term_id');
});
