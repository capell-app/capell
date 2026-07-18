<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Contracts\RedirectResolver;
use Capell\Core\Data\RedirectDecisionData;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Builder;

final class PageUrlRedirectResolver implements RedirectResolver
{
    public function __construct(
        private readonly PageUrlRedirectHitRecorder $redirectRecorder,
    ) {}

    public function resolve(Site $site, Language $language, string $url, ?int $pageId = null, ?PageUrl $pageUrl = null): ?RedirectDecisionData
    {
        $pageUrl ??= $this->findPageUrl($site, $language, $url, $pageId);
        $isWildcardHomeRedirect = false;

        if (! $pageUrl instanceof PageUrl && $pageId === null) {
            $pageUrl = $this->findWildcardHomeRedirect($site, $language);
            $isWildcardHomeRedirect = $pageUrl instanceof PageUrl;
        }

        if (! $pageUrl instanceof PageUrl || ! $pageUrl->isRedirect()) {
            return null;
        }

        $this->redirectRecorder->recordHit($pageUrl);

        if ($pageUrl->hasTargetUrl()) {
            $statusCode = $pageUrl->getAttribute('status_code');

            return new RedirectDecisionData(
                targetUrl: $isWildcardHomeRedirect
                    ? $this->appendRequestedPath((string) $pageUrl->target_url, $url)
                    : (string) $pageUrl->target_url,
                statusCode: $statusCode instanceof RedirectStatusCodeEnum ? $statusCode->value : 301,
            );
        }

        $targetPageUrl = $this->findCurrentPageUrl($site, $language, $pageUrl);

        if (! $targetPageUrl instanceof PageUrl) {
            return null;
        }

        return new RedirectDecisionData(targetUrl: $targetPageUrl->url, statusCode: 301);
    }

    private function findPageUrl(Site $site, Language $language, string $url, ?int $pageId = null): ?PageUrl
    {
        return PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where('url', $url)
            ->enabled()
            ->when($pageId, fn (Builder $query): Builder => $query->where('pageable_id', $pageId))
            ->first();
    }

    private function findWildcardHomeRedirect(Site $site, Language $language): ?PageUrl
    {
        return PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where('url', '/*')
            ->activeRedirects()
            ->whereNotNull('target_url')
            ->where('target_url', '!=', '')
            ->first();
    }

    private function findCurrentPageUrl(Site $site, Language $language, PageUrl $redirectPageUrl): ?PageUrl
    {
        return PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where('pageable_type', $redirectPageUrl->pageable_type)
            ->where('pageable_id', $redirectPageUrl->pageable_id)
            ->enabled()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('type')
                    ->orWhere('type', '!=', UrlTypeEnum::Redirect);
            })
            ->first();
    }

    private function appendRequestedPath(string $targetUrl, string $requestedPath): string
    {
        $target = rtrim($targetUrl, '/');
        $normalizedPath = $requestedPath === '' || $requestedPath === '/' ? '/' : '/' . ltrim($requestedPath, '/');

        if ($normalizedPath !== '/') {
            $target .= $normalizedPath;
        }

        $rawQuery = (string) request()->server->get('QUERY_STRING', '');
        if ($rawQuery !== '') {
            $target .= '?' . $rawQuery;
        }

        return $target;
    }
}
