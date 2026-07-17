<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\RedirectResolver;
use Capell\Core\Data\RedirectDecisionData;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\PageResolutionData;
use Capell\Frontend\Data\PublicPageResolutionInputData;
use Capell\Frontend\Support\Loader\PageLoader;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicPageRequestAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendLogger $logger) {}

    public function handle(PublicPageResolutionInputData $input): PageResolutionData
    {
        $pageUrl = $this->findPageUrl($input->site, $input->language, $input->url);
        $redirect = $this->resolveRedirect($input->site, $input->language, $input->url, $pageUrl);

        if ($redirect instanceof RedirectResponse) {
            return new PageResolutionData(page: null, redirect: $redirect);
        }

        $page = $this->resolvePage($input, $pageUrl);

        if ($page instanceof Pageable) {
            return new PageResolutionData(page: $page);
        }

        $wildcardResolution = $this->resolveViaWildcard($input);

        if ($wildcardResolution->page instanceof Pageable) {
            return $wildcardResolution;
        }

        if ($input->abortMissingForBot) {
            $this->logger->info('[Frontend] Page not found for bot request; aborting 404');

            return new PageResolutionData(
                page: null,
                shouldAbort404: true,
                attemptedWildcard: $wildcardResolution->attemptedWildcard,
            );
        }

        $this->logger->info('[Frontend] Page not found, falling back to error page');

        $errorPage = ResolveSystemPageAction::run(PageTypeEnum::NotFound->value, $input->site, $input->language);

        if (! $errorPage instanceof Pageable) {
            $this->logger->error('[Frontend] No error page found; aborting 404');

            return new PageResolutionData(
                page: null,
                shouldAbort404: true,
                attemptedWildcard: $wildcardResolution->attemptedWildcard,
            );
        }

        return new PageResolutionData(
            page: $errorPage,
            attemptedWildcard: $wildcardResolution->attemptedWildcard,
            isErrorPage: true,
        );
    }

    private function resolvePage(PublicPageResolutionInputData $input, ?PageUrl $pageUrl): ?Pageable
    {
        $resolution = ResolvePublicPageByUrlAction::run(
            $input->site,
            $input->language,
            $input->url,
            $input->revisionPageId,
            $pageUrl,
        );

        if (! $resolution->found() && $input->revisionPageId !== null) {
            $this->logger->debug('[Frontend] Revision not found, falling back to base page', [
                'baseUrl' => $input->url,
            ]);

            $resolution = ResolvePublicPageByUrlAction::run(
                $input->site,
                $input->language,
                $input->url,
                null,
                $pageUrl,
            );
        }

        if ($resolution->page instanceof Pageable) {
            return $resolution->page;
        }

        $this->logger->info('[Frontend] Page not found; attempting wildcard lookup', [
            'url' => $input->url,
            'revisionPageId' => $input->revisionPageId,
        ]);

        return null;
    }

    private function resolveRedirect(Site $site, Language $language, string $url, ?PageUrl $pageUrl): ?RedirectResponse
    {
        $redirectDecision = resolve(RedirectResolver::class)->resolve(
            site: $site,
            language: $language,
            url: $url,
            pageUrl: $pageUrl,
        );

        if (! $redirectDecision instanceof RedirectDecisionData) {
            return null;
        }

        return redirect($redirectDecision->targetUrl, $redirectDecision->statusCode);
    }

    private function findPageUrl(Site $site, Language $language, string $url): ?PageUrl
    {
        $normalizedUrl = $url === '' || $url === '/' ? '/' : '/' . trim($url, '/');

        return PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where('url', $normalizedUrl)
            ->where('status', true)
            ->first();
    }

    private function resolveViaWildcard(PublicPageResolutionInputData $input): PageResolutionData
    {
        if ($input->wildcardAlreadyAttempted) {
            return new PageResolutionData(page: null);
        }

        $pageUrl = $this->findWildcardPageUrl($input->site, $input->language, $input->url);

        if (! $pageUrl instanceof PageUrl) {
            $this->logger->debug('[Frontend] Wildcard lookup failed', ['url' => $input->url]);

            return new PageResolutionData(page: null, attemptedWildcard: true);
        }

        if (! is_string($pageUrl->pageable_type) || ! is_numeric($pageUrl->pageable_id)) {
            $this->logger->debug('[Frontend] Wildcard lookup found a non-page url record', [
                'url' => $input->url,
                'pageUrl' => $pageUrl->full_url,
            ]);

            return new PageResolutionData(page: null, attemptedWildcard: true);
        }

        $page = PageLoader::loadPage(
            type: $pageUrl->pageable_type,
            id: (int) $pageUrl->pageable_id,
            site: $input->site,
            language: $input->language,
        );

        if (! $page instanceof Pageable) {
            return new PageResolutionData(page: null, attemptedWildcard: true);
        }

        if ($page->url_params === null) {
            $this->logger->info('[Frontend] Wildcard lookup found but page has no url_params defined, returning 404', [
                'url' => $input->url,
                'pageUrl' => $pageUrl->full_url,
            ]);

            return new PageResolutionData(page: null, attemptedWildcard: true);
        }

        $pageUrl->setRelation('pageable', $page);
        $pageUrl->setRelation('siteDomain', $input->site->siteDomain);

        $this->logger->info('[Frontend] Wildcard lookup found', [
            'url' => $input->url,
            'pageUrl' => $pageUrl->full_url,
        ]);

        $pattern = rtrim($pageUrl->url, '/');
        $requestPath = rtrim($input->url, '/');
        $patternSegments = explode('/', $pattern);
        $requestSegments = explode('/', $requestPath);
        $hasWildcard = in_array('*', $patternSegments, true) && count($requestSegments) > count($patternSegments);

        $urlParts = ParseWildcardPageUrlAction::run(
            $pageUrl,
            $input->url,
            [],
            config('paginateroute.mode'),
            ['enforceInvalidPageValue' => $this->shouldEnforceInvalidPageValue($page)],
        );

        if (($urlParts['invalidPagination'] ?? false) === true) {
            $this->logger->debug('[Frontend] Wildcard pagination is invalid', ['url' => $input->url]);

            return new PageResolutionData(page: null, attemptedWildcard: true);
        }

        if (! isset($urlParts['params']) && ! $hasWildcard) {
            $this->logger->debug('[Frontend] Wildcard params extraction failed', ['url' => $input->url]);

            return new PageResolutionData(page: null, attemptedWildcard: true);
        }

        $params = $urlParts['params'] ?? [];
        $slug = isset($urlParts['pageSlug']) && is_string($urlParts['pageSlug'])
            ? $urlParts['pageSlug']
            : null;
        $pageQuery = isset($params['page']) && $params['page'] !== ''
            ? (string) $params['page']
            : null;

        return new PageResolutionData(
            page: $page,
            attemptedWildcard: true,
            params: $params,
            slug: $slug,
            routeUri: $pageQuery !== null ? $pageUrl->url : null,
            pageQuery: $pageQuery,
        );
    }

    private function findWildcardPageUrl(Site $site, Language $language, string $url, int $limit = 2): ?PageUrl
    {
        $urlParts = explode('/', mb_trim($url, '/'));
        array_pop($urlParts);

        $attempts = 0;

        while (count($urlParts) >= 1 && $attempts < $limit) {
            $attempts++;
            $urlPart = '/' . implode('/', $urlParts) . '/*';

            $pageUrl = $this->getWildcardPageUrl($site, $language, $urlPart);

            if (! $pageUrl instanceof PageUrl) {
                if (count($urlParts) === 1) {
                    $homeLevelUrl = '/' . implode('/', $urlParts);
                    $pageUrl = $this->findPageUrl($site, $language, $homeLevelUrl);

                    if ($pageUrl instanceof PageUrl) {
                        return $pageUrl;
                    }
                }

                array_pop($urlParts);

                continue;
            }

            return $pageUrl;
        }

        return null;
    }

    private function getWildcardPageUrl(Site $site, Language $language, string $url): ?PageUrl
    {
        $publicPageableMorphTypes = ResolvePublicPageableMorphTypesAction::run();

        if ($publicPageableMorphTypes === []) {
            return null;
        }

        $pageUrl = PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where('url', $url)
            ->whereNull('type')
            ->enabled()
            ->whereIn('pageable_type', $publicPageableMorphTypes)
            ->whereHasMorph(
                'pageable',
                $publicPageableMorphTypes,
                fn (BuilderContract $pageableQuery): BuilderContract => $pageableQuery
                    ->whereHas('blueprint', fn (BuilderContract $blueprintQuery): BuilderContract => $blueprintQuery->enabled()),
            )
            ->first();

        if (! $pageUrl instanceof PageUrl) {
            return null;
        }

        $this->setPageUrlRelations($pageUrl, $site, $language);

        return $pageUrl;
    }

    private function setPageUrlRelations(PageUrl $pageUrl, Site $site, Language $language): void
    {
        $pageUrl->setRelation('site', $site);
        $pageUrl->setRelation('language', $language);
    }

    private function shouldEnforceInvalidPageValue(Pageable $page): bool
    {
        $urlParams = $page->url_params ?? [];

        if (array_key_exists('slug', $urlParams)) {
            return false;
        }

        $nonSlugKeys = array_values(array_filter(array_keys($urlParams), fn (string $key): bool => $key !== 'slug'));

        return $nonSlugKeys === ['page'] && (string) ($urlParams['page'] ?? '') === 'int';
    }
}
