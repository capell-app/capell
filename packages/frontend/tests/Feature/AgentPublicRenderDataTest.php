<?php

declare(strict_types=1);

use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Agent\AgentPublicRenderDataContributor;
use Capell\Frontend\Support\Cache\PublicRenderDataCacheDependencyRegistry;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Facades\DB;

it('hydrates a public semantic graph through the contributor registry without leaking model metadata', function (): void {
    $page = Page::factory()->withTranslations()->create(['visible_from' => now()->subDay()]);
    $set = PropertySet::factory()->create(['key' => 'test.article']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'wordCount', 'semantic' => 'schema:wordCount',
        'type' => PropertyType::Number,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id, 'site_id' => $page->site_id,
        'property_definition_id' => $definition->id, 'value_number' => 1200,
    ]);
    $context = new FrontendRenderContextData($page, $page->site, null, null, null);
    $registry = resolve(PublicRenderDataContributorRegistry::class);
    expect($registry->all())->toHaveKey('agent');
    $contribution = resolve(AgentPublicRenderDataContributor::class)->contribute($context);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $encoded = json_encode($contribution->value, JSON_THROW_ON_ERROR);

    expect($encoded)->toContain('wordCount', '1200')
        ->not->toContain('property_definition_id', 'blueprint_id', 'test.article', 'site_id', 'page_id')
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('changes the contribution fingerprint when a property value changes without touching the page', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $value = PagePropertyValue::factory()->create(['page_id' => $page->id, 'site_id' => $page->site_id, 'value_text' => 'Before']);
    $context = new FrontendRenderContextData($page, $page->site, null, null, null);
    $contributor = resolve(AgentPublicRenderDataContributor::class);
    $before = $contributor->metadata($context)->fingerprint;
    $value->update(['value_text' => 'After']);

    expect($contributor->metadata($context)->fingerprint)->not->toBe($before);
});

it('invalidates recorded term output when the first property value is added', function (): void {
    $term = Term::factory()->create();
    $cache = resolve(CapellCacheManager::class);
    $dependencies = resolve(PublicRenderDataCacheDependencyRegistry::class);
    $key = 'agent-term-first-value-test';
    $cache->setToCache($key, ['before' => true], 0);
    $dependencies->register(PublicRenderDataCacheDependencyData::forModel($term), $key);
    expect($cache->getFreshFromCache($key))->toBe(['before' => true]);

    TermPropertyValue::factory()->create(['term_id' => $term->id]);

    expect($cache->getFreshFromCache($key))->toBeNull();
});

it('renders the anonymous page with semantic islands and no authoring bridge', function (): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false]);
    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost', 'scheme' => 'http', 'path' => null, 'default' => true,
    ])->create();
    $page = Page::factory()->site($site)->home()
        ->withTranslations(data: ['title' => 'Public agent page'], slug: '/')
        ->create(['meta' => null, 'visible_from' => now()->subDay()]);
    $set = PropertySet::factory()->create(['key' => 'private-internal-set-name']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id, 'key' => 'internal-field-name',
        'semantic' => 'schema:description', 'type' => PropertyType::Text,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $page->blueprint_id, 'property_set_id' => $set->id]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id, 'site_id' => $site->id,
        'property_definition_id' => $definition->id, 'value_text' => 'Curated public description',
    ]);

    $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost'])->assertOk()
        ->assertSee('data-capell-agent-schema', false)
        ->assertSee('data-capell-agent-tools', false)
        ->assertSee('Curated public description')
        ->assertDontSee('private-internal-set-name')
        ->assertDontSee('internal-field-name')
        ->assertDontSee('property_definition_id')
        ->assertDontSee('blueprint_id')
        ->assertDontSee('agentAdminBridge')
        ->assertDontSee('confirmationToken')
        ->assertDontSee('admin.page.')
        ->assertDontSee('capell-agent-admin');

    // Themes may replace app.blade.php; the shared BodyEnd contract must
    // still expose the already hydrated islands without performing queries.
    DB::enableQueryLog();
    DB::flushQueryLog();
    $hook = resolve(RenderHookRegistry::class)
        ->renderAll(RenderHookLocation::BodyEnd);
    expect($hook)->toContain('data-capell-agent-schema', 'data-capell-agent-tools', 'Curated public description')
        ->not->toContain('private-internal-set-name', 'confirmationToken')
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('invalidates a referring page when a referenced page is withdrawn', function (): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $target = Page::factory()->site($page->site)->create(['visible_from' => now()->subDay()]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id,
        'site_id' => $page->site_id,
        'referenced_page_id' => $target->id,
    ]);
    $context = new FrontendRenderContextData($page, $page->site, null, null, null);
    $metadata = resolve(AgentPublicRenderDataContributor::class)->metadata($context);
    $cache = resolve(CapellCacheManager::class);
    $dependencies = resolve(PublicRenderDataCacheDependencyRegistry::class);
    $key = 'agent-referenced-page-withdrawal';
    $cache->setToCache($key, ['reference' => 'visible'], 0);
    foreach ($metadata->cacheDependencies as $dependency) {
        $dependencies->register($dependency, $key);
    }

    expect($cache->getFreshFromCache($key))->toBe(['reference' => 'visible']);

    $target->update(['visible_from' => now()->addDay()]);

    expect($cache->getFreshFromCache($key))->toBeNull();
});

it('invalidates an omitted reference when its first public representation is created', function (string $child): void {
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $target = Page::factory()->site($page->site)->create(['visible_from' => now()->subDay()]);
    PagePropertyValue::factory()->create([
        'page_id' => $page->id, 'site_id' => $page->site_id, 'referenced_page_id' => $target->id,
    ]);
    $context = new FrontendRenderContextData($page, $page->site, null, null, null);
    $metadata = resolve(AgentPublicRenderDataContributor::class)->metadata($context);
    $cache = resolve(CapellCacheManager::class);
    $dependencies = resolve(PublicRenderDataCacheDependencyRegistry::class);
    $key = 'agent-reference-first-translation';
    $cache->setToCache($key, ['reference' => null], 0);
    foreach ($metadata->cacheDependencies as $dependency) {
        $dependencies->register($dependency, $key);
    }

    expect($cache->getFreshFromCache($key))->toBe(['reference' => null]);

    $language = Language::factory()->create();
    if ($child === 'translation') {
        $target->translations()->create(['language_id' => $language->id, 'title' => 'New public title']);
    } else {
        $target->pageUrls()->create([
            'site_id' => $target->site_id, 'language_id' => $language->id,
            'url' => '/new-public-target', 'status' => true,
        ]);
    }

    expect($cache->getFreshFromCache($key))->toBeNull();
})->with(['translation', 'url']);
