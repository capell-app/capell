<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\Frontend\Support\Maintenance\MaintenancePagePathResolver;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static array<int, array<string, mixed>> run(Site $site)
 */
final class GenerateMaintenancePageCacheAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ThemePreviewRendererInterface $renderer,
        private readonly MaintenancePagePathResolver $pathResolver,
        private readonly MaintenanceManifestStore $manifestStore,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function handle(Site $site): array
    {
        throw_unless(app()->bound(StaticMaintenancePageStore::class), RuntimeException::class, 'No static maintenance page store is registered.');

        $store = resolve(StaticMaintenancePageStore::class);
        $site->loadMissing(['language', 'siteDomains.language', 'theme', 'translations', 'logo']);

        $page = resolve(PageCreator::class)->createMaintenancePage($site, $site->getAllLanguages());
        $domains = [];

        $site->siteDomains->each(function (SiteDomain $siteDomain) use ($page, $site, $store, &$domains): void {
            $language = $siteDomain->language ?? $site->language;

            if (! $language instanceof Language) {
                return;
            }

            $html = $this->renderHtml($site, $page, $siteDomain, $language);
            $path = $this->pathResolver->pathForDomain($siteDomain);

            $store->put($path, $html);

            $domains[] = [
                'scheme' => $siteDomain->scheme,
                'domain' => $siteDomain->domain,
                'path' => $siteDomain->path ?? '/',
                'site_id' => $site->id,
                'site_domain_id' => $siteDomain->id,
                'language_id' => $language->id,
                'file' => $path,
                'generated_at' => Date::now()->toIso8601String(),
            ];
        });

        $this->manifestStore->replaceSiteDomains($site->id, $domains);

        return $domains;
    }

    private function renderHtml(Site $site, Page $page, SiteDomain $siteDomain, Language $language): string
    {
        $siteTranslation = $site->translations->first(
            fn (Translation $translation): bool => $translation->language_id === $language->id,
        ) ?? $site->translation;

        $pageTranslation = $page->translations()->where('language_id', $language->id)->first();

        $renderSite = $site->fresh(['language', 'siteDomains.language', 'theme', 'logo']);
        $renderPage = $page->fresh(['layout', 'site', 'translations', 'blueprint']);

        if ($siteTranslation instanceof Translation) {
            $renderSite?->setRelation('translation', $siteTranslation);
        }

        if ($pageTranslation instanceof Translation) {
            $renderPage?->setRelation('translation', $pageTranslation);
        }

        if (! $renderSite instanceof Site || ! $renderPage instanceof Page) {
            return '';
        }

        $renderSiteDomain = $renderSite->siteDomains->firstWhere('id', $siteDomain->id) ?? $siteDomain;
        $renderSite->setRelation('siteDomain', $renderSiteDomain);

        $content = $this->renderer->render($renderSite->theme, $renderSite, $renderPage, $language, $renderSiteDomain)->getContent();

        return is_string($content) ? $content : '';
    }
}
