<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Data\RedirectDecisionData;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\RedirectResolver;

class NullRedirectResolver implements RedirectResolver
{
    public function resolve(Site $site, Language $language, string $url, ?int $pageId = null, ?PageUrl $pageUrl = null): ?RedirectDecisionData
    {
        $isWildcardHomeRedirect = false;

        if (! $pageUrl instanceof PageUrl && $pageId === null) {
            $pageUrl = PageUrl::query()
                ->where('site_id', $site->getKey())
                ->where('language_id', $language->getKey())
                ->where('url', '/*')
                ->activeRedirects()
                ->whereNotNull('target_url')
                ->where('target_url', '!=', '')
                ->first();
            $isWildcardHomeRedirect = $pageUrl instanceof PageUrl;
        }

        if (! $pageUrl instanceof PageUrl || ! $pageUrl->isRedirect()) {
            return null;
        }

        $statusCode = $pageUrl->status_code instanceof RedirectStatusCodeEnum
            ? $pageUrl->status_code->value
            : 301;

        if ($pageUrl->hasTargetUrl()) {
            return new RedirectDecisionData(
                $isWildcardHomeRedirect
                    ? $this->appendRequestedPath((string) $pageUrl->target_url, $url)
                    : (string) $pageUrl->target_url,
                $statusCode,
            );
        }

        $targetUrl = PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where('pageable_type', $pageUrl->pageable_type)
            ->where('pageable_id', $pageUrl->pageable_id)
            ->where('url', '!=', $url)
            ->where(fn ($query) => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->value('url');

        return is_string($targetUrl) ? new RedirectDecisionData($targetUrl, $statusCode) : null;
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
