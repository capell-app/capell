<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Themes;

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Enums\LivewirePageComponentEnum;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\View\ThemeChainResolver;
use Capell\Frontend\Support\View\ThemeViewRegistrar;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class FrontendThemePreviewRenderer implements ThemePreviewRendererInterface
{
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

        $this->seedPreviewContext($theme, $site, $page, $language, $layout, $siteDomain);
        $this->registerThemeViews($theme);

        return $this->renderPageComponent($page);
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
            ->withSite($site)
            ->withLanguage($language)
            ->withPage($page)
            ->withLayout($layout)
            ->withTheme($theme)
            ->withParams([])
            ->withSlug(null);

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

    private function renderPageComponent(Pageable $page): SymfonyResponse
    {
        $component = $this->getLivewireComponent($page);
        $instance = resolve('livewire')->new($component);
        $response = app()->call([$instance, '__invoke']);

        return $this->toResponse($response);
    }

    private function getLivewireComponent(Pageable $page): string
    {
        return ResolveRenderableComponentAction::run(
            RenderableTypeEnum::Page,
            $page->blueprint->meta['component'] ?? LivewirePageComponentEnum::Default->value,
            'livewire',
        );
    }

    private function toResponse(mixed $result): SymfonyResponse
    {
        if ($result instanceof SymfonyResponse) {
            return $result;
        }

        if ($result instanceof Response) {
            return $result;
        }

        return response($result);
    }
}
