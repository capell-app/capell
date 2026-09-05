<?php

declare(strict_types=1);

use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Page;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Agent\AgentPublicRenderDataContributor;
use Capell\Frontend\Support\Cache\PublicRenderDataCacheDependencyRegistry;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Support\Facades\File;

it('invalidates cached and exported taxonomy-only properties when their definition becomes private', function (): void {
    $page = Page::factory()->withTranslations()->published()->create();
    $set = PropertySet::factory()->create();
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'type' => PropertyType::Text,
        'semantic' => 'schema:description',
        'agent_visible' => true,
    ]);
    $taxonomy = Taxonomy::factory()->create(['site_id' => $page->site_id, 'property_set_id' => $set->id]);
    $term = Term::factory()->for($taxonomy)->create();
    TermPropertyValue::factory()->create([
        'term_id' => $term->id,
        'property_definition_id' => $definition->id,
        'value_text' => 'Visible taxonomy description',
    ]);
    $page->terms()->attach($term->id);
    $context = new FrontendRenderContextData($page, $page->site, null, null, null);
    $contributor = resolve(AgentPublicRenderDataContributor::class);
    $metadata = $contributor->metadata($context);
    $cache = resolve(CapellCacheManager::class);
    $dependencies = resolve(PublicRenderDataCacheDependencyRegistry::class);
    $key = 'agent-taxonomy-definition-visibility';
    $root = sys_get_temp_dir() . '/agent-taxonomy-' . bin2hex(random_bytes(8));
    config(['capell-frontend.static_artifacts_path' => $root]);
    $file = 'index.html';

    try {
        $output = json_encode($contributor->contribute($context)->value, JSON_THROW_ON_ERROR);
        expect($output)->toContain('Visible taxonomy description');
        resolve(StaticPageArtifactStore::class)->putHtml($file, $output);
        $cache->setToCache($key, $output, 0);
        foreach ($metadata->cacheDependencies as $dependency) {
            $dependencies->register($dependency, $key);
            $dependencies->registerStaticArtifact($dependency, $file, $metadata->surrogateKeys);
        }

        $definition->update(['agent_visible' => false]);

        expect($cache->getFreshFromCache($key))->toBeNull()
            ->and(File::exists($root . '/' . $file))->toBeFalse()
            ->and($contributor->metadata($context)->fingerprint)->not->toBe($metadata->fingerprint)
            ->and(json_encode($contributor->contribute($context)->value, JSON_THROW_ON_ERROR))
            ->not->toContain('Visible taxonomy description');
    } finally {
        File::deleteDirectory($root);
    }
});
