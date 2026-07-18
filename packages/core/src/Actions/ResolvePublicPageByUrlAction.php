<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\PublicPageFieldsData;
use Capell\Core\Data\PublicPageResolutionData;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static PublicPageResolutionData run(Site $site, Language $language, string $url, ?int $revisionPageId = null, ?PageUrl $resolvedPageUrl = null)
 */
class ResolvePublicPageByUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(
        Site $site,
        Language $language,
        string $url,
        ?int $revisionPageId = null,
        ?PageUrl $resolvedPageUrl = null,
    ): PublicPageResolutionData {
        $normalizedUrl = $this->normalizeUrl($url);

        $pageUrl = $this->usableResolvedPageUrl($resolvedPageUrl, $site, $language, $normalizedUrl)
            ?? $this->resolvePageUrl($site, $language, $normalizedUrl);

        if (! $pageUrl instanceof PageUrl) {
            $this->logResolutionFailure($site, $normalizedUrl, 'no_matching_page_url');

            return $this->missing($site, $language);
        }

        $page = $this->resolvePage($pageUrl, $site, $language, $revisionPageId);

        if (! $page instanceof Pageable) {
            // The URL matched a PageUrl row but the page itself was filtered out by
            // applyPublicPageConstraints (page-type enabled/accessible, published date,
            // morph map) or failed revision validation. This is the gate that hid the
            // homepage 404 on MySQL, so it gets its own reason.
            $this->logResolutionFailure($site, $normalizedUrl, 'page_not_resolvable');

            return $this->missing($site, $language);
        }

        $translation = $page->translation;

        if (! $translation instanceof Translation) {
            $this->logResolutionFailure($site, $normalizedUrl, 'no_translation');

            return $this->missing($site, $language);
        }

        return new PublicPageResolutionData(
            page: $page,
            site: $site,
            language: $language,
            layout: $page->layout,
            fields: new PublicPageFieldsData(
                url: $normalizedUrl,
                title: $translation->title,
                content: $translation->content,
                meta: (array) $translation->meta,
            ),
        );
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || $url === '/') {
            return '/';
        }

        return '/' . trim($url, '/');
    }

    private function resolvePageUrl(Site $site, Language $language, string $url): ?PageUrl
    {
        $publicPageableMorphTypes = ResolvePublicPageableMorphTypesAction::run();

        if ($publicPageableMorphTypes === []) {
            return null;
        }

        return PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where('url', $url)
            ->enabled()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('type')
                    ->orWhere('type', '!=', UrlTypeEnum::Redirect);
            })
            ->whereIn('pageable_type', $publicPageableMorphTypes)
            ->whereHasMorph(
                'pageable',
                $publicPageableMorphTypes,
                fn (BuilderContract $pageableQuery): BuilderContract => $this->applyPublicPageConstraints($pageableQuery, $site),
            )
            ->first();
    }

    /**
     * @return Pageable<Model>|null
     */
    private function resolvePage(
        PageUrl $pageUrl,
        Site $site,
        Language $language,
        ?int $revisionPageId,
    ): ?Pageable {
        $pageableId = $revisionPageId ?? $pageUrl->pageable_id;

        if ($pageUrl->pageable_type === null || $pageableId === null) {
            return null;
        }

        $modelClass = Relation::getMorphedModel($pageUrl->pageable_type) ?? $pageUrl->pageable_type;

        if (! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        if (! $this->revisionBelongsToPageUrl($modelClass, $pageUrl, $pageableId, $revisionPageId)) {
            return null;
        }

        $model = $this->applyPublicPageConstraints($modelClass::query(), $site)
            ->whereKey($pageableId)
            ->with([
                'layout',
                'pageUrls',
                'blueprint',
                'translation' => fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language->getKey()),
            ])
            ->first();

        if (! $model instanceof Pageable) {
            return null;
        }

        $this->setResolvedUrlRelations($model, $pageUrl, $site);

        return $model;
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function setResolvedUrlRelations(Pageable $page, PageUrl $pageUrl, Site $site): void
    {
        $site->loadMissing('siteDomains');

        foreach ($page->getRelation('pageUrls') as $relatedPageUrl) {
            $siteDomain = $site->siteDomains->firstWhere('language_id', $relatedPageUrl->language_id);

            $relatedPageUrl->setRelation('siteDomain', $siteDomain);
        }

        $siteDomain = $site->siteDomains->firstWhere('language_id', $pageUrl->language_id);

        $pageUrl->setRelation('siteDomain', $siteDomain);

        $page->setRelation('pageUrl', $pageUrl);
    }

    private function usableResolvedPageUrl(?PageUrl $pageUrl, Site $site, Language $language, string $url): ?PageUrl
    {
        if (! $pageUrl instanceof PageUrl) {
            return null;
        }

        if ($pageUrl->site_id !== $site->getKey()) {
            return null;
        }

        if ($pageUrl->language_id !== $language->getKey()) {
            return null;
        }

        if ($pageUrl->url !== $url) {
            return null;
        }

        if (! $pageUrl->status || $pageUrl->type === UrlTypeEnum::Redirect) {
            return null;
        }

        return $pageUrl;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function revisionBelongsToPageUrl(string $modelClass, PageUrl $pageUrl, int|string $pageableId, ?int $revisionPageId): bool
    {
        if ($revisionPageId === null || (int) $pageableId === (int) $pageUrl->pageable_id) {
            return true;
        }

        if ($modelClass !== Page::class) {
            return false;
        }

        $baseUuid = Page::query()
            ->whereKey($pageUrl->pageable_id)
            ->value('uuid');

        if (! is_string($baseUuid) || $baseUuid === '') {
            return false;
        }

        return Page::query()
            ->whereKey($revisionPageId)
            ->where('uuid', $baseUuid)
            ->exists();
    }

    private function applyPublicPageConstraints(BuilderContract $query, Site $site): BuilderContract
    {
        return $query
            ->where('site_id', $site->getKey())
            ->whereHas('blueprint', fn (BuilderContract $blueprintQuery): BuilderContract => $blueprintQuery->enabled()->accessible())
            ->publishedDate();
    }

    /**
     * Record why a public URL failed to resolve to a renderable page.
     *
     * Kept at debug level and limited to ids + a coded reason so it never leaks
     * authoring detail into anonymous output, while still naming the exact gate
     * that dropped the page (which is what turns a 404 investigation from hours
     * of elimination into a single log line).
     */
    private function logResolutionFailure(Site $site, string $url, string $reason): void
    {
        Log::debug('Public page did not resolve.', [
            'site_id' => $site->getKey(),
            'url' => $url,
            'reason' => $reason,
        ]);
    }

    private function missing(Site $site, Language $language): PublicPageResolutionData
    {
        return new PublicPageResolutionData(
            page: null,
            site: $site,
            language: $language,
            layout: null,
            fields: new PublicPageFieldsData,
        );
    }
}
