<?php

declare(strict_types=1);

use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Translation;

it('does not expose the agent API when disabled', function (): void {
    config(['capell.agent.read_api' => false]);

    $this->getJson('/agent/v1/pages?set=commerce.product')->assertNotFound();
});

it('does not fall back to another site on an unknown host', function (): void {
    $this->getJson('https://unknown-agent-host.invalid/agent/v1/pages?set=commerce.product')->assertNotFound();
});

it('serves only the resolved sites taxonomy names with public contract headers', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    Taxonomy::factory()->create(['site_id' => $domain->site_id, 'key' => 'public-brands']);
    Taxonomy::factory()->create(['key' => 'other-site-secret']);

    $this->getJson('/agent/v1/taxonomies')->assertOk()
        ->assertHeader('X-Capell-Agent-Schema', '1')
        ->assertHeader('Cache-Control', 'max-age=60, public')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.key', 'public-brands')
        ->assertDontSee('site_id')->assertDontSee('other-site-secret');
});

it('rate limits public reads without making the limit response cacheable', function (): void {
    config(['capell.agent.rate_limit' => 1]);
    SiteDomain::factory()->default()->create();

    $this->getJson('/agent/v1/taxonomies')->assertOk();
    $this->getJson('/agent/v1/taxonomies')->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
});

it('serves navigation from the resolved site and publication window', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $visible = Page::factory()->site($domain->site)->create(['visible_from' => now()->subDay()]);
    Translation::factory()->translatable($visible)->language($domain->language)->create(['title' => 'Visible navigation page']);
    $scheduled = Page::factory()->site($domain->site)->create(['visible_from' => now()->addDay()]);
    Translation::factory()->translatable($scheduled)->language($domain->language)->create(['title' => 'Scheduled private page']);

    $this->getJson('/agent/v1/navigation')->assertOk()
        ->assertJsonPath('capellAgentSchema', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Visible navigation page')
        ->assertDontSee('Scheduled private page')
        ->assertDontSee('pageable_id')->assertDontSee('site_id');
});

it('returns agent-visible unmapped properties without requiring a schema graph', function (): void {
    $domain = SiteDomain::factory()->default()->create();
    $page = Page::factory()->site($domain->site)->withTranslations($domain->language, data: ['title' => 'Public catalogue item'])->published()->create();
    $set = PropertySet::factory()->create(['key' => 'catalogue.details']);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    foreach ([['colour', 'Green', true], ['internal_note', 'Private marker', false]] as [$key, $value, $visible]) {
        $definition = PropertyDefinition::factory()->create([
            'property_set_id' => $set->id, 'key' => $key, 'type' => PropertyType::Text,
            'semantic' => null, 'agent_visible' => $visible,
        ]);
        PagePropertyValue::factory()->create([
            'page_id' => $page->id, 'site_id' => $domain->site_id,
            'property_definition_id' => $definition->id, 'value_text' => $value,
        ]);
    }

    $response = $this->getJson('/agent/v1/pages?set=catalogue.details')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Public catalogue item')
        ->assertDontSee('Private marker')->assertDontSee('internal_note')
        ->assertDontSee('page_id')->assertDontSee('blueprint_id')->assertDontSee('property_definition_id');
    expect($response->json('data.0.properties'))->toBe(['capell:catalogue.details.colour' => 'Green']);
});
