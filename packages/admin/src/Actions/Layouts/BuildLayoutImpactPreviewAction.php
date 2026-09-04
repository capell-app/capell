<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Layouts;

use Capell\Admin\Support\PageUrlPresenter;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\EditorImpact\EditorImpactPageData;
use Capell\Core\Data\EditorImpact\EditorImpactPreviewData;
use Capell\Core\Data\EditorImpact\EditorImpactUrlData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Impact\ImpactPlanFingerprint;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildLayoutImpactPreviewAction
{
    use AsFake;
    use AsObject;

    public function handle(Layout $layout): ?EditorImpactPreviewData
    {
        if (! $this->canUpdateLayout($layout)) {
            return null;
        }

        /** @var Collection<int, Page> $pages */
        $pages = collect();

        foreach (CapellCore::getPageVariationModels() as $pageClass) {
            if (! is_a($pageClass, Pageable::class, true)) {
                continue;
            }

            /** @var class-string<Page&Pageable> $pageClass */
            $pages = $pages->merge(
                SiteScope::applyForCurrentActor(
                    $pageClass::query(),
                    denyWhenMissingActor: true,
                )
                    ->where('layout_id', $layout->getKey())
                    ->with([
                        'site',
                        'pageUrls.language',
                        'pageUrls.siteDomain',
                    ])
                    ->get()
                    ->filter(fn (Page $page): bool => Gate::allows('view', $page)),
            );
        }

        $impactPages = $pages
            ->map(fn (Page $page): EditorImpactPageData => $this->pageData($page))
            ->sortBy(fn (EditorImpactPageData $page): string => $page->site . '|' . $page->name . '|' . $page->type)
            ->values();

        $preview = new EditorImpactPreviewData(
            pageCount: $impactPages->count(),
            siteCount: $pages->pluck('site_id')->unique()->count(),
            localeCount: $impactPages
                ->flatMap(fn (EditorImpactPageData $page): array => $page->locales)
                ->unique()
                ->count(),
            pages: array_values($impactPages->all()),
        );

        return $preview->withFingerprint(
            ImpactPlanFingerprint::for($layout, $preview->planPayload()),
        );
    }

    private function pageData(Page $page): EditorImpactPageData
    {
        $site = $page->getRelation('site');
        $urls = collect($page->pageUrls)
            ->filter(fn (PageUrl $pageUrl): bool => $pageUrl->status && ! $pageUrl->isRedirect())
            ->map(fn (PageUrl $pageUrl): EditorImpactUrlData => new EditorImpactUrlData(
                locale: $this->locale($pageUrl),
                url: $this->publicUrl($pageUrl),
            ))
            ->sortBy(fn (EditorImpactUrlData $url): string => $url->locale . '|' . $url->url)
            ->values();

        return new EditorImpactPageData(
            name: (string) $page->getAttribute('name'),
            type: class_basename($page),
            site: $site instanceof Site
                ? (string) $site->getAttribute('name')
                : (string) __('capell-admin::generic.layout_impact_preview_unknown_site'),
            locales: array_values($urls->pluck('locale')->unique()->values()->all()),
            urls: array_values($urls->all()),
        );
    }

    private function publicUrl(PageUrl $pageUrl): string
    {
        $url = PageUrlPresenter::displayUrl($pageUrl);
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return preg_replace('#/{2,}#', '/', $url) ?? $url;
        }

        $originalPath = (string) ($parts['path'] ?? '');
        $path = preg_replace('#/{2,}#', '/', $originalPath) ?? $originalPath;
        $authority = $parts['host'];

        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $parts['scheme'] . '://' . $authority . $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    private function locale(PageUrl $pageUrl): string
    {
        $language = $pageUrl->getRelation('language');
        $locale = $language->getAttribute('locale') ?: $language->getAttribute('code');

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) $language->getAttribute('name');
    }

    private function canUpdateLayout(Layout $layout): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable || ! Gate::forUser($actor)->allows('update', $layout)) {
            return false;
        }

        if ($layout->site_id === null || SiteScope::isGlobalActor($actor)) {
            return true;
        }

        return method_exists($actor, 'getAssignedSiteIds')
            && $actor->getAssignedSiteIds()->contains($layout->site_id);
    }
}
