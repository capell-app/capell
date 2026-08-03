<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Locale;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Loader\SiteLoader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Resolves the exact per-page sibling URL for a visitor's preferred language,
 * cheaply enough to run before the HTTP kernel pipeline.
 *
 * Cost: site and language resolution is served entirely from the in-memory
 * `SiteLoader::getSites()` cache (the same collection `SiteResolver` uses), so
 * only TWO indexed queries are added, and only on a first-visit, cookie-less,
 * non-crawler GET:
 *
 *   1. the landed-on `page_urls` row  — hits `page_urls_site_language_url_unique`
 *   2. the sibling `page_urls` row    — hits `page_urls_site_language_pageable_index`
 *
 * There is deliberately NO fallback to the language homepage. Sending a visitor
 * to a different page than the one they asked for is worse than leaving them on
 * a page they can still translate themselves, so a missing sibling means "do
 * nothing".
 *
 * The eligibility filter here (`type IS NULL`, enabled, translation present)
 * mirrors the hreflang alternate cluster in
 * `resources/views/components/app/head/index.blade.php` exactly. If the two ever
 * diverge, the site would redirect to a URL it does not advertise, or advertise
 * one it refuses to redirect to.
 */
final class VisitorLanguageSiblingResolver
{
    public function __construct(private readonly AcceptLanguageMatcher $matcher) {}

    public function resolve(Request $request): ?VisitorLanguageMatch
    {
        try {
            return $this->attempt($request);
        } catch (Throwable) {
            // Detection is an enhancement. It must never be able to fail a
            // request that would otherwise render.
            return null;
        }
    }

    private function attempt(Request $request): ?VisitorLanguageMatch
    {
        $sites = SiteLoader::getSites();

        if ($sites->isEmpty()) {
            return null;
        }

        $resolved = LoadSiteDomainFromUrlAction::run($request->fullUrl(), sites: $sites);

        if (! is_array($resolved) || ! $resolved[0] instanceof SiteDomain) {
            return null;
        }

        [$siteDomain, $path] = $resolved;
        $site = $sites->firstWhere('id', $siteDomain->site_id);
        $currentLanguage = $siteDomain->language;

        if (! $site instanceof Site || ! $currentLanguage instanceof Language) {
            return null;
        }

        $currentTag = $this->tag($currentLanguage);
        $acceptLanguage = $request->headers->get('Accept-Language');

        // A visitor who lists this page's language at any q-value has said they
        // read it. Never move them.
        if ($currentTag === '' || $this->matcher->accepts($acceptLanguage, $currentTag)) {
            return null;
        }

        $domainsByLanguage = $this->domainsByLanguage($site, $currentLanguage);

        if ($domainsByLanguage === []) {
            return null;
        }

        $tags = array_map(fn (SiteDomain $domain): string => $this->tag($domain->language), $domainsByLanguage);
        $targetLanguageId = $this->matcher->bestMatch($acceptLanguage, $tags);

        if (! is_int($targetLanguageId)) {
            return null;
        }

        $targetDomain = $domainsByLanguage[$targetLanguageId];
        $landed = $this->eligibleUrl($site, (int) $currentLanguage->getKey(), $this->normalisePath($path));

        if (! $landed instanceof PageUrl || $landed->pageable_type === null || $landed->pageable_id === null) {
            return null;
        }

        $sibling = $this->eligibleSiblingUrl($site, $targetLanguageId, $landed);

        if (! $sibling instanceof PageUrl) {
            return null;
        }

        // full_url needs the domain; supply the already-in-memory one rather
        // than letting the accessor issue a third query.
        $sibling->setRelation('siteDomain', $targetDomain);

        return new VisitorLanguageMatch(
            currentTag: $currentTag,
            targetTag: $tags[$targetLanguageId],
            targetUrl: $sibling->full_url,
        );
    }

    /**
     * Enabled domains of this site, one per language, excluding the current one.
     *
     * @return array<int, SiteDomain>
     */
    private function domainsByLanguage(Site $site, Language $currentLanguage): array
    {
        $domains = [];

        foreach ($site->siteDomains as $domain) {
            $languageId = (int) $domain->language_id;

            if (! $domain->status || $languageId === (int) $currentLanguage->getKey()) {
                continue;
            }

            if (! $domain->language instanceof Language || $this->tag($domain->language) === '') {
                continue;
            }

            $domains[$languageId] ??= $domain;
        }

        return $domains;
    }

    private function eligibleUrl(Site $site, int $languageId, string $url): ?PageUrl
    {
        return $this->eligible($site, $languageId)
            ->where('url', $url)
            ->first();
    }

    private function eligibleSiblingUrl(Site $site, int $languageId, PageUrl $landed): ?PageUrl
    {
        return $this->eligible($site, $languageId)
            ->where('pageable_type', $landed->pageable_type)
            ->where('pageable_id', $landed->pageable_id)
            ->first();
    }

    /** @return Builder<PageUrl> */
    private function eligible(Site $site, int $languageId): Builder
    {
        return PageUrl::query()
            ->where('site_id', $site->getKey())
            ->where('language_id', $languageId)
            ->whereNull('type')
            ->enabled()
            ->whereHas('translation');
    }

    private function normalisePath(mixed $path): string
    {
        $path = is_string($path) ? $path : '/';

        return $path === '' || $path === '/' ? '/' : '/' . mb_trim($path, '/');
    }

    private function tag(?Language $language): string
    {
        if (! $language instanceof Language) {
            return '';
        }

        return $this->matcher->normalise(
            filled($language->locale) ? (string) $language->locale : (string) $language->code,
        );
    }
}
