<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Aimeos\Nestedset\Collection as NestedsetCollection;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Events\SiteReplicated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static Site run(Site $source, array<string, mixed> $formData = [])
 */
final class SiteReplicatedAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $formData
     */
    public function handle(Site $source, array $formData = []): Site
    {
        $sourceSite = Site::query()->findOrFail((int) $source->getKey());

        $sourceAttributes = $sourceSite->getAttributes();
        $fillableInput = array_intersect_key($formData, array_flip((new Site)->getFillable()));

        if (isset($sourceAttributes['default'])) {
            $fillableInput['default'] = 0;
        }

        $replica = $sourceSite->duplicate();
        throw_if($replica === null, RuntimeException::class, 'Site could not be duplicated.');

        $replica->fill($fillableInput);

        if ($sourceSite->timestamps) {
            $replica->created_at = CarbonImmutable::now();
            $replica->updated_at = CarbonImmutable::now();
        }

        $replica->save();

        $replacementPages = $this->replicateRelations($sourceSite, $replica, $formData);

        event(new SiteReplicated($sourceSite, $replica, $formData, $replacementPages));

        return $replica;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array<int|string, Pageable<Model>|Page>
     */
    private function replicateRelations(Site $source, Site $replica, array $formData): array
    {
        $languages = $this->resolveLanguages($source, $formData);

        $languages->each(fn (Language $language) => $this->replicateSiteTranslation($source, $replica, $language));

        $this->replicateDomains($replica, $formData['site_domains'] ?? []);

        $replacementPages = [];

        if (($formData['copy_pages'] ?? null) === true) {
            $replacementPages = $this->replicatePageTree($source, $replica, $languages);
        } elseif (($formData['setup_pages'] ?? null) === true
            && isset($formData['auto_create_pages'])
            && is_array($formData['auto_create_pages'])
            && $formData['auto_create_pages'] !== []
        ) {
            $this->createDefaultPages($replica, $formData['auto_create_pages']);
        }

        return $replacementPages;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return Collection<int, Language>
     */
    private function resolveLanguages(Site $source, array $formData): Collection
    {
        $ids = [];
        if (isset($formData['language_id'])) {
            $ids[] = $formData['language_id'];
        }

        if (isset($formData['languages'])) {
            $ids = array_merge($ids, (array) $formData['languages']);
        } elseif (! isset($formData['language_id'])) {
            $ids = array_merge($ids, $source->languages->pluck('id')->all());
        }

        $ids = array_unique(array_filter($ids, static fn (mixed $id): bool => $id !== null && $id !== ''));

        return Language::query()->whereIn('id', $ids)->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $domains
     */
    private function replicateDomains(Site $site, array $domains): void
    {
        $isFirst = true;

        foreach ($domains as $domain) {
            $urlParts = parse_url((string) ($domain['url'] ?? ''));
            $scheme = $urlParts['scheme'] ?? null;
            $host = $urlParts['host'] ?? null;
            $path = $urlParts['path'] ?? null;

            $site->siteDomains()->create([
                'language_id' => $domain['language_id'] ?? null,
                'scheme' => $scheme,
                'domain' => $host,
                'path' => $path,
                'default' => $isFirst,
            ]);

            $isFirst = false;
        }
    }

    /**
     * @param  Collection<int, Language>  $languages
     * @return array<int|string, Pageable<Model>|Page>
     */
    private function replicatePageTree(Site $source, Site $replica, Collection $languages): array
    {
        $replacementPages = [];

        /** @var Builder<Page> $pagesQuery */
        $pagesQuery = $source->pages()->getQuery();

        $pagesQuery = collect(app()->tagged('capell-admin:page-table-extender'))
            ->reduce(
                fn (Builder $carry, object $extender): Builder => method_exists($extender, 'modifyQuery')
                    ? $extender->modifyQuery($carry)
                    : $carry,
                $pagesQuery,
            );

        $pages = $pagesQuery->with(['translations.language', 'pageUrls'])->get();

        $tree = new NestedsetCollection($pages->all())->toTree();

        foreach ($tree as $page) {
            if (! $page instanceof Page) {
                continue;
            }

            $replacementPages = $this->replicatePage($page, $replica, $languages, $replacementPages);
        }

        return $replacementPages;
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     * @param  Collection<int, Language>  $languages
     * @param  array<int|string, Pageable<Model>|Page>  $replacementPages
     * @return array<int|string, Pageable<Model>|Page>
     */
    private function replicatePage(
        Pageable $page,
        Site $site,
        Collection $languages,
        array $replacementPages,
        ?Page $parentPage = null,
    ): array {
        $replica = $page->duplicateExcept(['deleted_at', 'deleted_by']);

        $replica->site()->associate($site);

        if ($parentPage instanceof Pageable) {
            $replica->parent()->associate($parentPage);
        } else {
            $replica->parent_id = null;
        }

        $replica->saveQuietly();

        $replacementPages[$page->id] = $replica;

        $languages->each(function (Language $language) use ($page, $replica): void {
            $this->replicateTranslation($page, $replica, $language);
            $this->replicatePageUrl($page, $replica, $language);
        });

        $replica->save();

        if ($page->children->isNotEmpty()) {
            $page->children->each(function (Page $child) use ($site, $languages, &$replacementPages, $replica): void {
                $childReplicaMap = $this->replicatePage(
                    $child,
                    $site,
                    $languages,
                    $replacementPages,
                    parentPage: $replica,
                );

                $replacementPages = $childReplicaMap;
            });
        }

        return $replacementPages;
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function replicatePageUrl(Pageable $page, Page $replica, Language $language): void
    {
        $pageUrl = $page->pageUrls->firstWhere('language_id', $language->id)
            ?? $page->pageUrls->first()
            ?? $page->pageUrls()->make();

        $urlReplica = PageUrl::query()
            ->where('site_id', $replica->site_id)
            ->where('language_id', $language->id)
            ->where('pageable_type', $replica->getMorphClass())
            ->where('pageable_id', $replica->getKey())
            ->first();

        if (! $urlReplica instanceof PageUrl) {
            /** @var PageUrl $urlReplica */
            $urlReplica = $pageUrl->replicate();
            $urlReplica->language()->associate($language);
            $urlReplica->pageable()->associate($replica);
            $urlReplica->site()->associate($replica->site);
        }

        $urlReplica->fill($pageUrl->only([
            'url',
            'target_url',
            'status_code',
            'is_manual',
            'hit_count',
            'last_hit_at',
            'notes',
            'type',
            'status',
        ]));
        $urlReplica->save();
    }

    /**
     * @param  array<int, string>  $pages
     */
    private function createDefaultPages(Site $site, array $pages): void
    {
        if (! app()->bound('capell.admin.create-default-pages-action')) {
            return;
        }

        app()->call(resolve('capell.admin.create-default-pages-action'), [
            'site' => $site,
            'pages' => $pages,
        ]);
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function replicateTranslation(Pageable $page, Page $replica, Language $language): void
    {
        if ($replica->translations()->where(['language_id' => $language->id])->first() !== null) {
            return;
        }

        $sourceTranslation = $page->translations->firstWhere('language_id', $language->id)
            ?? $page->translations->first()
            ?? $page->translations()->make();

        /** @var Translation $clone */
        $clone = $sourceTranslation->replicate();
        $clone->language()->associate($language);
        $clone->translatable()->associate($replica);
        $clone->save();
    }

    private function replicateSiteTranslation(Site $source, Site $replica, Language $language): void
    {
        $existing = $source->translations()->firstWhere('language_id', $language->id);

        $attributes = [
            'language_id' => $language->id,
            'translatable_type' => $replica->getMorphClass(),
            'translatable_id' => $replica->id,
        ];

        $values = ['title' => $replica->name];

        if ($existing !== null) {
            $values = array_merge($values, $existing->only(['content', 'meta']));
        }

        $replica->translations()->updateOrCreate($attributes, $values);
    }
}
