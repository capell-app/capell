<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\QueryPagesByPropertiesAction;
use Capell\Core\Data\Properties\AgentPageQueryData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Illuminate\Validation\ValidationException;

it('queries taxonomy-only properties while respecting blueprint visibility overrides', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $set = PropertySet::factory()->create(['key' => 'test.taxonomy-query']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'score', 'type' => PropertyType::Number,
        'agent_visible' => true, 'locked' => false,
    ]);
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id, 'property_set_id' => $set->id]);
    $term = Term::factory()->for($taxonomy)->create();
    $page->terms()->attach($term->id);
    TermPropertyValue::factory()->create(['term_id' => $term->id, 'property_definition_id' => $definition->id, 'value_number' => 17]);
    $input = new AgentPageQueryData(set: $set->key, filters: ['score' => ['eq' => 17]], sort: 'score');

    expect(QueryPagesByPropertiesAction::run($page->site, $input)->getCollection()->modelKeys())->toBe([$page->id]);

    $definition->update(['agent_visible' => false]);
    expect(QueryPagesByPropertiesAction::run($page->site, $input)->total())->toBe(0);

    $definition->update(['agent_visible' => true]);
    BlueprintPropertySet::factory()->create([
        'blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id,
        'overrides' => ['score' => ['agent_visible' => false]],
    ]);
    expect(QueryPagesByPropertiesAction::run($page->site, $input)->total())->toBe(0);
});

it('filters typed money within the current site and excludes unpublished pages', function (): void {
    $set = PropertySet::factory()->create(['key' => 'test.query']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'price', 'type' => PropertyType::Money,
        'agent_visible' => true, 'localised' => false, 'multiple' => false,
    ]);
    $visible = Page::factory()->create(['visible_from' => now()->subDay()]);
    $draft = Page::factory()->create(['site_id' => $visible->site_id, 'visible_from' => now()->addYear()]);
    $foreign = Page::factory()->create(['visible_from' => now()->subDay()]);
    foreach ([$visible, $draft, $foreign] as $page) {
        BlueprintPropertySet::query()->firstOrCreate(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
        PagePropertyValue::factory()->create([
            'page_id' => $page->id, 'site_id' => $page->site_id,
            'property_definition_id' => $definition->id, 'value_number' => 25, 'currency' => 'GBP',
            'translation_id' => null,
        ]);
    }

    $result = QueryPagesByPropertiesAction::run($visible->site, new AgentPageQueryData(
        set: 'test.query',
        filters: ['price' => ['lte' => 50, 'currency' => 'GBP']],
    ));

    expect($result->getCollection()->modelKeys())->toBe([$visible->id]);
});

it('rejects unknown operators rather than interpolating them into SQL', function (): void {
    $set = PropertySet::factory()->create(['key' => 'test.query']);
    PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'price', 'type' => PropertyType::Money,
        'agent_visible' => true, 'localised' => false, 'multiple' => false,
    ]);
    $page = Page::factory()->create();

    expect(fn (): mixed => QueryPagesByPropertiesAction::run($page->site, new AgentPageQueryData(
        set: 'test.query',
        filters: ['price' => ['raw' => '1=1']],
    )))->toThrow(ValidationException::class);
});

it('uses the winning inherited term and lets a page value take precedence', function (): void {
    $site = Site::factory()->create();
    $set = PropertySet::factory()->create(['key' => 'test.inherited']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'score', 'type' => PropertyType::Number, 'agent_visible' => true,
    ]);
    $page = Page::factory()->site($site)->create(['visible_from' => now()->subDay()]);
    BlueprintPropertySet::query()->firstOrCreate(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);

    $firstTaxonomy = Taxonomy::factory()->create(['site_id' => $site->id, 'position' => 1]);
    $secondTaxonomy = Taxonomy::factory()->create(['site_id' => $site->id, 'position' => 2]);
    $winningTerm = Term::factory()->create(['taxonomy_id' => $firstTaxonomy->id]);
    $losingTerm = Term::factory()->create(['taxonomy_id' => $secondTaxonomy->id]);
    $page->terms()->attach([$winningTerm->id => ['position' => 1], $losingTerm->id => ['position' => 2]]);
    TermPropertyValue::factory()->create(['term_id' => $winningTerm->id, 'property_definition_id' => $definition->id, 'value_number' => 10]);
    TermPropertyValue::factory()->create(['term_id' => $losingTerm->id, 'property_definition_id' => $definition->id, 'value_number' => 20]);

    expect(QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.inherited',
        filters: ['score' => ['eq' => 10]],
    ))->getCollection()->modelKeys())->toBe([$page->id]);

    PagePropertyValue::factory()->create([
        'page_id' => $page->id, 'site_id' => $site->id, 'property_definition_id' => $definition->id, 'value_number' => 99,
    ]);
    expect(QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.inherited',
        filters: ['score' => ['eq' => 10]],
    ))->getCollection()->modelKeys())->toBe([]);
    expect(QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.inherited',
        filters: ['score' => ['eq' => 99]],
    ))->getCollection()->modelKeys())->toBe([$page->id]);
});

it('matches localised multiple values and falls back to the page-level row', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $site = Site::factory()->language($english)->create();
    $set = PropertySet::factory()->create(['key' => 'test.locale']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'label', 'type' => PropertyType::Text,
        'agent_visible' => true, 'localised' => true, 'multiple' => true,
    ]);
    $page = Page::factory()->site($site)->withTranslations($english)->create(['visible_from' => now()->subDay()]);
    $frenchTranslation = Translation::factory()->translatable($page)->language($french)->create();
    BlueprintPropertySet::query()->firstOrCreate(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id, 'site_id' => $site->id, 'property_definition_id' => $definition->id,
        'translation_id' => $frenchTranslation->id, 'position' => 0, 'value_text' => 'bonjour',
    ]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id, 'site_id' => $site->id, 'property_definition_id' => $definition->id,
        'translation_id' => $frenchTranslation->id, 'position' => 1, 'value_text' => 'salut',
    ]);

    $fallbackPage = Page::factory()->site($site)->withTranslations($english)->create(['visible_from' => now()->subDay()]);
    BlueprintPropertySet::query()->firstOrCreate(['blueprint_id' => $fallbackPage->blueprint_id, 'property_set_id' => $set->id]);
    PagePropertyValue::factory()->create([
        'page_id' => $fallbackPage->id, 'site_id' => $site->id, 'property_definition_id' => $definition->id,
        'translation_id' => null, 'value_text' => 'fallback',
    ]);

    expect(QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.locale',
        filters: ['label' => ['eq' => 'salut']],
        languageId: $french->id,
    ))->getCollection()->modelKeys())->toBe([$page->id]);
    expect(QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.locale',
        filters: ['label' => ['eq' => 'fallback']],
        languageId: $french->id,
    ))->getCollection()->modelKeys())->toBe([$fallbackPage->id]);
});

it('keeps URL filtering before pagination and excludes drafts and foreign pages', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $set = PropertySet::factory()->create(['key' => 'test.pagination']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'score', 'type' => PropertyType::Number, 'agent_visible' => true,
    ]);
    $makePage = fn (int $siteId, ?DateTimeInterface $visibleFrom = null): Page => Page::factory()->site($siteId)->create(['visible_from' => $visibleFrom ?? now()->subDay()]);
    $eligible = Page::factory()->site($site)->withTranslations($site->language)->published()->create();
    $withoutUrl = $makePage($site->id);
    $draft = $makePage($site->id, now()->addYear());
    $foreign = $makePage(Site::factory()->create()->id);
    foreach ([$eligible, $withoutUrl, $draft, $foreign] as $page) {
        BlueprintPropertySet::query()->firstOrCreate(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
        PagePropertyValue::factory()->create([
            'page_id' => $page->id, 'site_id' => $page->site_id, 'property_definition_id' => $definition->id, 'value_number' => 5,
        ]);
    }

    $language = $site->language;
    PageUrl::factory()->page($eligible)->language($language)->site($site)->create();

    $result = QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.pagination',
        filters: ['score' => ['eq' => 5]],
        size: 1,
        languageId: $language->id,
        publicUrlRequired: true,
    ));
    expect($result->total())->toBe(1)->and($result->getCollection()->modelKeys())->toBe([$eligible->id]);
});

it('excludes pages whose only public URL uses a disabled site domain', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $set = PropertySet::factory()->create(['key' => 'test.disabled-domain']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'score',
        'type' => PropertyType::Number,
        'agent_visible' => true,
    ]);
    $page = Page::factory()->site($site)->published()->create();
    BlueprintPropertySet::query()->firstOrCreate(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id,
        'site_id' => $site->id,
        'property_definition_id' => $definition->id,
        'value_number' => 5,
    ]);
    PageUrl::factory()->page($page)->language($site->language)->site($site)->create();
    $site->siteDomains()->update(['status' => false]);

    $result = QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.disabled-domain',
        filters: ['score' => ['eq' => 5]],
        languageId: $site->language_id,
        publicUrlRequired: true,
    ));

    expect($result->total())->toBe(0);
});

it('matches a non-first inherited value from the winning term for a multiple property', function (): void {
    $site = Site::factory()->create();
    $set = PropertySet::factory()->create(['key' => 'test.inherited.multiple']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'scores',
        'type' => PropertyType::Number,
        'multiple' => true,
        'agent_visible' => true,
    ]);
    $page = Page::factory()->site($site)->published()->create();
    BlueprintPropertySet::query()->firstOrCreate([
        'blueprint_id' => $page->blueprint_id,
        'property_set_id' => $set->id,
    ]);
    $taxonomy = Taxonomy::factory()->create(['site_id' => $site->id, 'position' => 0]);
    $term = Term::factory()->for($taxonomy)->create();
    $page->terms()->attach($term->id, ['position' => 0]);
    TermPropertyValue::factory()->create([
        'term_id' => $term->id,
        'property_definition_id' => $definition->id,
        'position' => 0,
        'value_number' => 10,
    ]);
    TermPropertyValue::factory()->create([
        'term_id' => $term->id,
        'property_definition_id' => $definition->id,
        'position' => 1,
        'value_number' => 20,
    ]);

    $result = QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.inherited.multiple',
        filters: ['scores' => ['eq' => 20]],
    ));

    expect($result->getCollection()->modelKeys())->toBe([$page->id]);
});

it('does not match a non-first duplicate row for a scalar property', function (): void {
    $site = Site::factory()->create();
    $set = PropertySet::factory()->create(['key' => 'test.scalar.duplicate']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'score',
        'type' => PropertyType::Number,
        'multiple' => false,
        'agent_visible' => true,
    ]);
    $page = Page::factory()->site($site)->published()->create();
    BlueprintPropertySet::query()->firstOrCreate([
        'blueprint_id' => $page->blueprint_id,
        'property_set_id' => $set->id,
    ]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id,
        'site_id' => $site->id,
        'property_definition_id' => $definition->id,
        'position' => 0,
        'value_number' => 10,
    ]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id,
        'site_id' => $site->id,
        'property_definition_id' => $definition->id,
        'position' => 1,
        'value_number' => 20,
    ]);

    $result = QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: 'test.scalar.duplicate',
        filters: ['score' => ['eq' => 20]],
    ));

    expect($result->getCollection()->modelKeys())->toBe([]);
});

it('filters anonymous page eligibility before property query pagination', function (string $reason): void {
    $page = Page::factory()->withTranslations()->published()->create();
    $site = $page->site;
    $set = PropertySet::factory()->create(['key' => 'test.public-eligibility']);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    PageUrl::factory()->page($page)->site($site)->language($site->language)->create();

    match ($reason) {
        'private blueprint' => $page->blueprint->update(['meta' => ['accessible' => false]]),
        'disabled blueprint' => $page->blueprint->update(['status' => false]),
        'redirect' => $page->pageUrls()->update(['type' => UrlTypeEnum::Redirect]),
        'missing translation' => $page->translations()->delete(),
        default => throw new InvalidArgumentException($reason),
    };

    expect(QueryPagesByPropertiesAction::run($site, new AgentPageQueryData(
        set: $set->key,
        languageId: $site->language_id,
        publicUrlRequired: true,
    ))->total())->toBe(0);
})->with(['private blueprint', 'disabled blueprint', 'redirect', 'missing translation']);
