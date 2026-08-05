<?php

declare(strict_types=1);

namespace Capell\Frontend\View\Components;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout as LayoutModel;
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Contracts\FrontendContextReader;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

final class Layout extends Component
{
    public readonly ?Theme $theme;

    public readonly ?Pageable $page;

    public readonly ?LayoutModel $layout;

    public readonly ?Site $site;

    public function __construct(
        FrontendContextReader $frontend,
        private readonly Factory $view,
        public readonly ?string $containerClass = null,
        public readonly mixed $footer = null,
        public readonly mixed $header = null,
        public readonly ?string $mainClass = null,
        public readonly ?string $mainContainerClass = null,
        public readonly mixed $pageSlot = null,
    ) {
        $this->theme = $frontend->theme();
        $this->page = $frontend->page();
        $this->layout = $frontend->layout();
        $this->site = $frontend->site();
    }

    public function isSystemPageLayout(): bool
    {
        return data_get($this->layout?->admin ?? [], 'system_page_layout') === true;
    }

    public function siteHomeUrl(): string
    {
        if (! $this->site instanceof Site) {
            return '/';
        }

        foreach (['defaultDomain', 'siteDomain'] as $relation) {
            if (! $this->site->relationLoaded($relation)) {
                continue;
            }

            $url = data_get($this->site->getRelation($relation), 'url');

            if (is_string($url)) {
                return $url;
            }
        }

        return '/';
    }

    public function siteLogoBladeView(): ?string
    {
        if (! $this->site instanceof Site) {
            return null;
        }

        $bladeView = $this->site->getMeta('logo_blade_view', 'brand.capell-logo');

        return is_string($bladeView) && $this->view->exists($bladeView)
            ? $bladeView
            : null;
    }

    public function siteLogo(): ?Media
    {
        return $this->loadedRelation($this->site, 'logo', Media::class);
    }

    public function siteTranslation(): ?Translation
    {
        return $this->loadedRelation($this->site, 'translation', Translation::class);
    }

    public function pageTranslation(): ?Translation
    {
        return $this->loadedRelation($this->page, 'translation', Translation::class);
    }

    public function pageType(): ?Blueprint
    {
        return $this->loadedRelation($this->page, 'blueprint', Blueprint::class);
    }

    public function htmlContentStructure(): ContentStructure
    {
        return ContentStructure::Html;
    }

    public function render(): View
    {
        return $this->view->make('capell::components.layout.index');
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $expectedType
     * @return TModel|null
     */
    private function loadedRelation(Model|Pageable|null $model, string $relation, string $expectedType): ?Model
    {
        if (! $model instanceof Model || ! $model->relationLoaded($relation)) {
            return null;
        }

        $value = $model->getRelation($relation);

        return $value instanceof $expectedType ? $value : null;
    }
}
