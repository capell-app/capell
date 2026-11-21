<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\SiteAiDisclosure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Frontend\Data\PublicLayoutShellData;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Projects the frontend records behind the shared layout shell into public render data. */
final class BuildPublicLayoutShellDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Factory $view, private readonly ThemeChromeRegistry $chrome) {}

    public function handle(?Theme $theme, ?Pageable $page, ?Layout $layout, ?Site $site): PublicLayoutShellData
    {
        $themeMeta = is_array($theme?->meta) ? $theme->meta : [];
        $isSystemPageLayout = data_get($layout?->admin ?? [], 'system_page_layout') === true;
        $logoView = $this->siteLogoView($site);
        $logo = $isSystemPageLayout && $logoView === null ? $this->loadedRelation($site, 'logo', Media::class) : null;
        $siteTranslation = $this->loadedRelation($site, 'translation', Translation::class);
        $pageTranslation = $this->loadedRelation($page, 'translation', Translation::class);
        $pageType = $this->loadedRelation($page, 'blueprint', Blueprint::class);
        $pageContent = $pageTranslation?->content;
        $headerFile = $this->chromeFile($themeMeta, 'header');
        $footerFile = $this->chromeFile($themeMeta, 'footer');

        return new PublicLayoutShellData(
            isSystemPageLayout: $isSystemPageLayout,
            homeUrl: $this->siteHomeUrl($site),
            siteDisplayName: $siteTranslation?->title ?? $site?->name,
            logoView: $logoView,
            renderedMediaLogo: $logo instanceof Model ? Blade::render('<x-capell::logo :media="$logo" />', ['logo' => $logo]) : null,
            pageTitle: (string) ($pageTranslation?->title ?? ''),
            pageContent: is_array($pageContent) || is_string($pageContent) ? $pageContent : '',
            pageContentStructure: $pageType?->content_structure ?? ContentStructure::Html,
            headerFile: $headerFile,
            headerFileIsView: $headerFile !== null && $this->view->exists($headerFile),
            footerFile: $footerFile,
            footerFileIsView: $footerFile !== null && $this->view->exists($footerFile),
            aiNotice: $isSystemPageLayout ? null : $this->aiNotice($site, $page),
        );
    }

    /** The visitor-facing AI notice. Read from the already-loaded site and page meta: no query, and no provenance identifiers. */
    private function aiNotice(?Site $site, ?Pageable $page): ?string
    {
        $siteMeta = is_array($site?->meta) ? $site->meta : [];
        $rawPageMeta = $page instanceof Model ? $page->getAttribute('meta') : null;
        $pageMeta = is_array($rawPageMeta) ? $rawPageMeta : [];

        if (! filter_var($siteMeta[SiteAiDisclosure::NOTICE_META_KEY] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $value = $pageMeta[AiDisclosure::PAGE_META_KEY] ?? null;
        $disclosure = is_string($value) ? AiDisclosure::tryFrom($value) : null;

        if ($disclosure === null || $disclosure === AiDisclosure::None) {
            return null;
        }

        $notice = __('capell-frontend::generic.ai_notice');

        return is_string($notice) ? $notice : null;
    }

    /** @param  array<array-key, mixed>  $themeMeta */
    private function chromeFile(array $themeMeta, string $region): ?string
    {
        if (($themeMeta[$region] ?? null) === false) {
            return null;
        }

        $file = $themeMeta[$region . '_file'] ?? null;

        return is_string($file) && $file !== '' && $file !== '0' ? $file : null;
    }

    private function siteHomeUrl(?Site $site): string
    {
        if (! $site instanceof Site) {
            return '/';
        }

        foreach (['defaultDomain', 'siteDomain'] as $relation) {
            if (! $site->relationLoaded($relation)) {
                continue;
            }

            $url = data_get($site->getRelation($relation), 'url');

            if (is_string($url)) {
                return $url;
            }
        }

        return '/';
    }

    private function siteLogoView(?Site $site): ?string
    {
        if (! $site instanceof Site) {
            return null;
        }

        $bladeView = $site->getMeta('logo_blade_view', 'brand.capell-logo');

        return is_string($bladeView)
            && array_key_exists($bladeView, $this->chrome->logoOptions())
            && $this->view->exists($bladeView)
            ? $bladeView
            : null;
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
