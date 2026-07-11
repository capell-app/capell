<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Override;

class SitemapPage extends Page
{
    use HasPageShield;

    #[Url]
    public ?int $language_id = null;

    #[Url]
    public ?int $site_id = null;

    /** @var Collection<int, Language>|null */
    protected ?Collection $site_languages = null;

    /** @var Collection<int|string, mixed>|null */
    protected ?Collection $sitemap = null;

    /** @var Collection<int, Site>|null */
    protected ?Collection $sites = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Map;

    protected static ?string $slug = 'sitemap';

    protected string $view = 'capell-admin::filament.pages.sitemap';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-admin::generic.sitemap');
    }

    #[Override]
    public function getView(): string
    {
        return 'capell-admin::filament.pages.sitemap';
    }

    /**
     * @return Collection<int|string, mixed>|null
     */
    public function getSitemap(): ?Collection
    {
        $site = null;
        $domain = null;

        if ($this->site_id !== null && $this->language_id !== null) {
            $site = $this->getSites()->firstWhere('id', $this->site_id);

            if ($site === null) {
                return null;
            }

            $domain = $site->siteDomains->firstWhere('language_id', $this->language_id);
        }

        if (! $site instanceof Site || $domain === null || $domain->language === null) {
            return null;
        }

        $builderClass = 'Capell\\SiteDiscovery\\Support\\Sitemap\\SitemapBuilder';

        if (! class_exists($builderClass)) {
            return null;
        }

        $sitemapLoader = new $builderClass(
            site: $site,
            domain: $domain,
            language: $domain->language,
            withEditUrl: true,
        );

        if (! is_callable([$sitemapLoader, 'build'])) {
            return null;
        }

        $sitemap = call_user_func([$sitemapLoader, 'build']);

        return $sitemap instanceof Collection ? $sitemap : null;
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::generic.sitemap');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.sitemap_info');
    }

    public function mount(): void
    {
        $this->sites = $this->getSites();

        $this->site_id = request()->integer('site_id');

        if ($this->site_id === 0) {
            $this->site_id = $this->getDefaultSite()?->id;
        }

        $this->site_languages = $this->getSiteLanguage();

        $this->language_id = request()->integer('language_id');

        if ($this->language_id === 0) {
            $this->language_id = $this->getDefaultSiteLanguage()?->id;
        }
    }

    public function updatedSiteId(): void
    {
        $this->getSiteLanguage();
        $this->language_id = $this->getDefaultSiteLanguage()?->id;
    }

    /**
     * @return Collection<int, Site>
     */
    protected function fetchSites(): Collection
    {
        /** @var class-string<Site> $model */
        $model = Site::class;

        return SiteScope::applyForCurrentActor($model::query(), 'id', denyWhenMissingActor: true)
            ->with([
                'languages',
                'translations.language',
                'siteDomains.language',
            ])
            ->ordered()
            ->get();
    }

    protected function getDefaultSite(): ?Site
    {
        $sites = $this->getSites();

        $site = $this->site_id !== null && $this->site_id !== 0 ? $sites->firstWhere('id', $this->site_id) : $sites->firstWhere('default', true);

        if ($site === null) {
            return $sites->first();
        }

        return $site;
    }

    protected function getDefaultSiteLanguage(): ?Language
    {
        if (! $this->site_languages instanceof Collection || $this->site_languages->isEmpty()) {
            return null;
        }

        foreach ($this->site_languages as $language) {
            if ($language->default) {
                return $language;
            }
        }

        return $this->site_languages->first();
    }

    /**
     * @return Collection<int, Language>|null
     */
    protected function getSiteLanguage(): ?Collection
    {
        if ($this->site_id === null || $this->site_id === 0) {
            return null;
        }

        $sites = $this->getSites();

        $site = $sites->firstWhere('id', $this->site_id);

        if (! $site instanceof Site) {
            $this->site_languages = collect();

            return $this->site_languages;
        }

        $this->site_languages = $site->languages;

        return $this->site_languages;
    }

    /**
     * @return Collection<int, Site>
     */
    protected function getSites(): Collection
    {
        if (! $this->sites instanceof Collection || $this->sites->isEmpty()) {
            $this->sites = $this->fetchSites();
        }

        return $this->sites;
    }

    #[Override]
    protected function getViewData(): array
    {
        return [
            'sites' => $this->getSites(),
            'site_languages' => $this->getSiteLanguage(),
            'sitemap' => $this->getSitemap(),
        ];
    }
}
