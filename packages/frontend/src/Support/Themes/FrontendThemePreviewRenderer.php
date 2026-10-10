<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Themes;

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\PrepareFrontendRenderAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\View\ThemeChainResolver;
use Capell\Frontend\Support\View\ThemeViewRegistrar;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Override;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class FrontendThemePreviewRenderer implements ThemePreviewRendererInterface
{
    #[Override]
    public function render(
        Theme $theme,
        Site $site,
        Page $page,
        ?Language $language = null,
        ?SiteDomain $siteDomain = null,
    ): SymfonyResponse {
        $page->loadMissing(['layout', 'site.language', 'translations.language']);
        $site->loadMissing(['language', 'siteDomains']);

        $language ??= $site->language;
        $layout = $page->layout;

        abort_unless($language instanceof Language, SymfonyResponse::HTTP_NOT_FOUND);
        abort_unless($layout instanceof Layout, SymfonyResponse::HTTP_NOT_FOUND);

        $page->load([
            'ancestors.translations',
            'blueprint',
            'canonicalPage.pageUrls',
            'image',
            'pageUrl' => fn (Relation $query): Relation => $this->enabledPageUrlForLanguage($query, $language),
            'pageUrl.siteDomain',
            'pageUrls.siteDomain.language',
            'translation' => fn (Relation $query): Relation => $this->translationForLanguage($query, $language),
            'translation.language',
            'translations.language',
        ]);

        $this->seedPreviewContext($theme, $site, $page, $language, $layout, $siteDomain);
        $this->registerThemeViews($theme);

        $prepared = PrepareFrontendRenderAction::run(
            resolve(FrontendContextReader::class),
            new FrontendRenderContextData($page, $site, $language, $layout, $theme),
        );
        abort_unless($prepared->renderer !== null, SymfonyResponse::HTTP_SERVICE_UNAVAILABLE);

        $response = $prepared->renderer->render($prepared->renderContext);
        if ($response instanceof Responsable) {
            $response = $response->toResponse(request());
        }

        abort_unless($response instanceof SymfonyResponse, SymfonyResponse::HTTP_SERVICE_UNAVAILABLE);

        $response->headers->set('Content-Type', $response->headers->get('Content-Type', 'text/html; charset=UTF-8'));

        return $response;
    }

    private function seedPreviewContext(
        Theme $theme,
        Site $site,
        Page $page,
        Language $language,
        Layout $layout,
        ?SiteDomain $siteDomain,
    ): void {
        $site->setRelation('theme', $theme);
        $layout->setRelation('theme', $theme);
        $page->setRelation('site', $site);
        $page->setRelation('layout', $layout);

        resolve(FrontendState::class)
            ->reset()
            ->withSite($site)
            ->withLanguage($language)
            ->withPage($page)
            ->withLayout($layout)
            ->withTheme($theme)
            ->withParams([])
            ->withSlug(null)
            ->setFrontendData('renderAudience', FrontendRenderAudience::Preview);

        if ($siteDomain instanceof SiteDomain) {
            resolve(FrontendState::class)->withDomain($siteDomain);
            $site->setRelation('siteDomain', $siteDomain);
        }
    }

    private function registerThemeViews(Theme $theme): void
    {
        $paths = resolve(ThemeChainResolver::class)->resolve($theme);

        resolve(ThemeViewRegistrar::class)->register($paths, $theme->key);
    }

    /**
     * @param  Relation<PageUrl, Page, ?PageUrl>  $query
     * @return Relation<PageUrl, Page, ?PageUrl>
     */
    private function enabledPageUrlForLanguage(Relation $query, Language $language): Relation
    {
        return $query->where('language_id', $language->getKey())->whereNull('type')->enabled();
    }

    /**
     * @param  Relation<Translation, Page, ?Translation>  $query
     * @return Relation<Translation, Page, ?Translation>
     */
    private function translationForLanguage(Relation $query, Language $language): Relation
    {
        return $query->where('language_id', $language->getKey());
    }
}
