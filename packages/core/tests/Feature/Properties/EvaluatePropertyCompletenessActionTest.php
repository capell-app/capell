<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;

function attachCompletenessDefinition(Page $page, PropertyRequirement $requirement): PropertyDefinition
{
    $propertySet = PropertySet::factory()->create(['key' => 'test.completeness.page']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $propertySet->id,
        'key' => 'requiredPageValue',
        'type' => PropertyType::Text,
        'requirement' => $requirement,
    ]);

    BlueprintPropertySet::factory()->create([
        'blueprint_id' => $page->blueprint_id,
        'property_set_id' => $propertySet->id,
    ]);

    return $definition;
}

it('inherits required definitions from an assigned same-site taxonomy', function (): void {
    $page = Page::factory()->create();
    $propertySet = PropertySet::factory()->create(['key' => 'test.completeness.term']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $propertySet->id,
        'key' => 'requiredTermValue',
        'type' => PropertyType::Text,
        'requirement' => PropertyRequirement::Contract,
    ]);
    $taxonomy = Taxonomy::factory()->create([
        'site_id' => $page->site_id,
        'property_set_id' => $propertySet->id,
    ]);
    $term = Term::factory()->for($taxonomy)->create();
    $page->terms()->attach($term->id, ['position' => 0]);

    $missing = EvaluatePropertyCompletenessAction::run($page->fresh());

    expect($missing->missingContractRequired)->toBe(['test.completeness.term.requiredTermValue']);

    TermPropertyValue::factory()->create([
        'term_id' => $term->id,
        'property_definition_id' => $definition->id,
        'value_text' => 'inherited',
    ]);

    expect(EvaluatePropertyCompletenessAction::run($page->fresh())->isAgentComplete())->toBeTrue();
});

it('ignores a page value row carrying a foreign site id', function (): void {
    $page = Page::factory()->create();
    $definition = attachCompletenessDefinition($page, PropertyRequirement::Publish);
    $foreignSite = Site::factory()->create();

    PagePropertyValue::factory()->create([
        'site_id' => $foreignSite->id,
        'page_id' => $page->id,
        'property_definition_id' => $definition->id,
        'value_text' => 'foreign row',
    ]);

    $result = EvaluatePropertyCompletenessAction::run($page->fresh());

    expect($result->missingPublishRequired)->toBe(['test.completeness.page.requiredPageValue']);
});

it('ignores required definitions inherited from a foreign taxonomy', function (): void {
    $page = Page::factory()->create();
    $propertySet = PropertySet::factory()->create(['key' => 'test.completeness.foreign']);
    PropertyDefinition::factory()->create([
        'property_set_id' => $propertySet->id,
        'key' => 'foreignRequired',
        'type' => PropertyType::Text,
        'requirement' => PropertyRequirement::Publish,
    ]);
    $foreignSite = Site::factory()->create();
    $taxonomy = Taxonomy::factory()->create([
        'site_id' => $foreignSite->id,
        'property_set_id' => $propertySet->id,
    ]);
    $term = Term::factory()->for($taxonomy)->create();
    $page->terms()->attach($term->id, ['position' => 0]);

    expect(EvaluatePropertyCompletenessAction::run($page)->missingPublishRequired)->toBe([]);
});
