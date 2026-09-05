<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Agent;

use Capell\Core\Actions\Agent\DiscoverAgentToolDefinitionsAction;
use Capell\Core\Actions\Properties\BuildAgentToolManifestAction;
use Capell\Core\Actions\Properties\BuildPageSchemaGraphAction;
use Capell\Core\Contracts\Agent\AgentPageSearch;
use Capell\Core\Data\SchemaGraphData;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Media\MediaModel;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AgentPublicRenderDataContributor implements PublicRenderDataContributor
{
    /** @var array<string, bool> */
    private array $hasInlineData = [];

    /** @var array<string, ?SchemaGraphData> */
    private array $graphs = [];

    /** @var array<string, bool> */
    private array $metadataPrepared = [];

    public function key(): string
    {
        return 'agent';
    }

    public function supports(FrontendRenderContextData $context): bool
    {
        return ! $context->isError
            && $context->page instanceof Page
            && resolve(RuntimeSchemaState::class)->hasTable('page_property_values');
    }

    public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
    {
        $graph = $this->graph($context);

        $this->hasInlineData[$this->contextKey($context)] = $graph instanceof SchemaGraphData;

        return new PublicRenderDataContributionData((object) [
            'graph' => $graph?->toJsonLd(),
            'manifest' => $this->toolManifest($graph instanceof SchemaGraphData),
        ]);
    }

    public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
    {
        if (! $context->page instanceof Page) {
            return new PublicRenderDataContributionMetadataData('agent-schema-1');
        }

        $page = $context->page;

        $contextKey = $this->contextKey($context);
        $graph = $this->graph($context, refresh: isset($this->metadataPrepared[$contextKey]));
        $this->metadataPrepared[$contextKey] = true;
        $this->hasInlineData[$contextKey] = $graph instanceof SchemaGraphData;

        // Empty pages still depend on their page row, but do not enumerate
        // property and term relations when the schema graph is empty.
        if (! $graph instanceof SchemaGraphData) {
            return new PublicRenderDataContributionMetadataData(
                fingerprint: hash('sha256', json_encode(['manifest' => $this->toolManifest(false), 'version' => 1], JSON_THROW_ON_ERROR)),
                surrogateKeys: ['page-' . $page->id],
                cacheDependencies: [PublicRenderDataCacheDependencyData::forModel($page)],
            );
        }

        $models = [$page];
        $values = [];
        if ($page->blueprint instanceof Blueprint) {
            $models[] = $page->blueprint;
        }

        foreach (BlueprintPropertySet::query()->where('blueprint_id', $page->blueprint_id)->with('propertySet.definitions')->get() as $attachment) {
            $models[] = $attachment;
            $set = $attachment->propertySet;
            if ($set instanceof PropertySet) {
                $models[] = $set;
                foreach ($set->definitions as $definition) {
                    $models[] = $definition;
                }
            }
        }

        foreach ($page->propertyValues()->where('site_id', $page->site_id)->get() as $value) {
            $models[] = $value;
            $values[] = $value;
        }

        $assignments = [];
        foreach ($page->terms()->with(['taxonomy.propertySet.definitions', 'propertyValues'])->get() as $term) {
            $taxonomy = $term->taxonomy;
            if (! $taxonomy instanceof Taxonomy) {
                continue;
            }

            if ($taxonomy->site_id !== $page->site_id) {
                continue;
            }

            $models[] = $term;
            $models[] = $taxonomy;
            // Taxonomy-only sets can contribute public values without a blueprint attachment.
            $set = $taxonomy->propertySet;
            if ($set instanceof PropertySet) {
                $models[] = $set;
                foreach ($set->definitions as $definition) {
                    $models[] = $definition;
                }
            }

            $pivot = $term->getRelationValue('pivot');
            $assignments[] = [$term->id, $pivot instanceof Model ? $pivot->getAttribute('position') : null];
            foreach ($term->propertyValues as $value) {
                $models[] = $value;
                $values[] = $value;
            }
        }

        array_push($models, ...$this->referencedModels($values, $page->site_id));
        $fingerprint = ['manifest' => $this->toolManifest(true), 'version' => 1, 'search' => app()->bound(AgentPageSearch::class), 'api' => config('capell.agent.read_api', true), 'assignments' => $assignments];
        $dependencies = [];
        foreach ($models as $model) {
            $dependency = PublicRenderDataCacheDependencyData::forModel($model);
            $dependencies[$dependency->identity()] = $dependency;
            $fingerprint[$dependency->identity()] = $model->getAttributes();
        }

        return new PublicRenderDataContributionMetadataData(
            fingerprint: hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)),
            surrogateKeys: ['page-' . $page->id],
            cacheDependencies: array_values($dependencies),
        );
    }

    /** @return list<class-string<Model>> */
    public function cacheDependencyModelTypes(): array
    {
        return [Blueprint::class, Page::class, BlueprintPropertySet::class, PropertySet::class, PropertyDefinition::class, PagePropertyValue::class, Taxonomy::class, Term::class, TermPropertyValue::class, MediaModel::class()];
    }

    /**
     * References affect rendered data even when the owning page is unchanged.
     * Include unpublished targets too: publication can make an omitted value appear.
     *
     * @param  list<PagePropertyValue|TermPropertyValue>  $values
     * @return list<Model>
     */
    private function referencedModels(array $values, int $siteId): array
    {
        $pageIds = [];
        $termIds = [];
        $mediaIds = [];
        foreach ($values as $value) {
            if ($value->referenced_page_id !== null) {
                $pageIds[] = $value->referenced_page_id;
            }

            $termId = $value instanceof PagePropertyValue ? $value->term_id : $value->referenced_term_id;
            if ($termId !== null) {
                $termIds[] = $termId;
            }

            if ($value->media_id !== null) {
                $mediaIds[] = $value->media_id;
            }
        }

        $models = [];
        if ($pageIds !== []) {
            foreach (Page::query()->where('site_id', $siteId)->whereKey(array_unique($pageIds))
                ->with(['translations', 'pageUrls.siteDomain', 'blueprint'])->get() as $reference) {
                $models[] = $reference;
                $blueprint = $reference->blueprint;
                if ($blueprint instanceof Blueprint) {
                    $models[] = $blueprint;
                }

                foreach ($reference->translations as $translation) {
                    $models[] = $translation;
                }

                foreach ($reference->pageUrls as $url) {
                    $models[] = $url;
                    $domain = $url->siteDomain;
                    if ($domain instanceof SiteDomain) {
                        $models[] = $domain;
                    }
                }
            }
        }

        if ($termIds !== []) {
            foreach (Term::query()->whereKey(array_unique($termIds))->whereHas(
                'taxonomy',
                static fn (Builder $query) => $query->where('site_id', $siteId),
            )->get() as $reference) {
                $models[] = $reference;
            }
        }

        if ($mediaIds !== []) {
            foreach (MediaModel::query()->whereKey(array_unique($mediaIds))->get() as $reference) {
                $models[] = $reference;
            }
        }

        return $models;
    }

    private function contextKey(FrontendRenderContextData $context): string
    {
        $page = $context->page;
        $site = $context->site;
        $language = $context->language;

        return implode(':', [
            $page instanceof Page ? (string) $page->getKey() : 'none',
            $site instanceof Model ? (string) $site->getKey() : 'none',
            $language instanceof Model ? (string) $language->getKey() : 'none',
        ]);
    }

    private function graph(FrontendRenderContextData $context, bool $refresh = false): ?SchemaGraphData
    {
        $key = $this->contextKey($context);
        if ($refresh) {
            unset($this->graphs[$key]);
        }

        if (array_key_exists($key, $this->graphs)) {
            return $this->graphs[$key];
        }

        $this->graphs[$key] = $context->page instanceof Page
            ? BuildPageSchemaGraphAction::run($context->page, $context->language)
            : null;

        return $this->graphs[$key];
    }

    /** @return array{capellAgentSchema: int, tools: list<array<string, mixed>>, messages: array{confirmForm: string}} */
    private function toolManifest(bool $hasInlineData): array
    {
        return BuildAgentToolManifestAction::run(
            $hasInlineData,
            (bool) config('capell.agent.read_api', true),
            app()->bound(AgentPageSearch::class),
            DiscoverAgentToolDefinitionsAction::run(),
        );
    }
}
