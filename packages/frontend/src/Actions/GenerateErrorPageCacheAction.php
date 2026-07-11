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
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Enums\ErrorPageStatusEnum;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Capell\Frontend\Support\Error\ErrorPagePathResolver;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static array<int, array<string, mixed>> run(Site $site)
 */
final class GenerateErrorPageCacheAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly ThemePreviewRendererInterface $renderer,
        private readonly ErrorPagePathResolver $pathResolver,
        private readonly ErrorPageManifestStore $manifestStore,
        private readonly ErrorPageFallbackManifestStore $fallbackManifestStore,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function handle(Site $site): array
    {
        throw_unless(app()->bound(StaticErrorPageStore::class), RuntimeException::class, 'No static error page store is registered.');

        $store = resolve(StaticErrorPageStore::class);
        $site->loadMissing(['language', 'siteDomains.language', 'theme', 'translations', 'logo']);

        $page = resolve(PageCreator::class)->createErrorPage($site, $site->getAllLanguages());
        $entries = [];
        $logoUrl = $this->logoUrl($site);

        $site->siteDomains->each(function (SiteDomain $siteDomain) use ($page, $site, $store, $logoUrl, &$entries): void {
            $language = $siteDomain->language ?? $site->language;

            if (! $language instanceof Language) {
                return;
            }

            $statusCopy = $this->statusCopy($page, $language);

            foreach (ErrorPageStatusEnum::cases() as $status) {
                $copyForStatus = $statusCopy[(int) $status->value] ?? null;

                $html = $this->renderHtml($site, $page, $siteDomain, $language, $copyForStatus);
                $path = $this->pathResolver->pathForDomainAndStatus($siteDomain, $status->value);

                $store->put($path, $html);

                $entries[] = [
                    'scheme' => $siteDomain->scheme,
                    'domain' => $siteDomain->domain,
                    'path' => $siteDomain->path ?? '/',
                    'site_id' => $site->id,
                    'site_domain_id' => $siteDomain->id,
                    'language_id' => $language->id,
                    'status' => $status->value,
                    'file' => $path,
                    'generated_at' => Date::now()->toIso8601String(),
                ];
            }

            $this->fallbackManifestStore->setHost(
                strtolower($siteDomain->domain),
                $logoUrl,
                $statusCopy,
            );
        });

        $this->manifestStore->replaceSite($site->id, $entries);

        $defaultLanguage = $site->language;
        $defaultCopy = $defaultLanguage instanceof Language ? $this->statusCopy($page, $defaultLanguage) : [];
        $this->fallbackManifestStore->setDefault($logoUrl, $defaultCopy);

        return $entries;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function statusCopy(Page $page, Language $language): array
    {
        $pageTranslation = $page->translations()->where('language_id', $language->id)->first();

        if (! $pageTranslation instanceof Translation) {
            return [];
        }

        $copy = $pageTranslation->meta['error_status_copy'] ?? [];

        if (! is_array($copy)) {
            return [];
        }

        $normalized = [];

        foreach ($copy as $status => $entry) {
            if (is_array($entry)) {
                $normalized[(int) $status] = [
                    'headline' => (string) ($entry['headline'] ?? ''),
                    'description' => (string) ($entry['description'] ?? ''),
                ];
            }
        }

        return $normalized;
    }

    private function logoUrl(Site $site): string
    {
        $url = $site->logo?->getUrl();

        return is_string($url) && $url !== '' ? $url : asset('capell-logo.svg');
    }

    /**
     * @param  array<string, string>|null  $copyForStatus
     */
    private function renderHtml(Site $site, Page $page, SiteDomain $siteDomain, Language $language, ?array $copyForStatus): string
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
            $statusTranslation = $this->applyStatusCopy($pageTranslation, $copyForStatus);
            $renderPage?->setRelation('translation', $statusTranslation);
        }

        if (! $renderSite instanceof Site || ! $renderPage instanceof Page) {
            return '';
        }

        $renderSiteDomain = $renderSite->siteDomains->firstWhere('id', $siteDomain->id) ?? $siteDomain;
        $renderSite->setRelation('siteDomain', $renderSiteDomain);

        $content = $this->renderer->render($renderSite->theme, $renderSite, $renderPage, $language, $renderSiteDomain)->getContent();

        return is_string($content) ? $content : '';
    }

    /**
     * Clone the translation in-memory and override title/content with the
     * per-status copy. Never persisted — render-only.
     *
     * @param  array<string, string>|null  $copyForStatus
     */
    private function applyStatusCopy(Translation $pageTranslation, ?array $copyForStatus): Translation
    {
        $statusTranslation = $pageTranslation->replicate();
        $statusTranslation->setRelations($pageTranslation->getRelations());

        if ($copyForStatus !== null) {
            $statusTranslation->title = $copyForStatus['headline'] ?? $pageTranslation->title;
            $statusTranslation->content = '<p>' . ($copyForStatus['description'] ?? '') . '</p>';
        }

        return $statusTranslation;
    }
}
