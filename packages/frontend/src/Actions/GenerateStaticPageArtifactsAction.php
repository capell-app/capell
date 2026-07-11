<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Support\Static\StaticPageArtifactPathResolver;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class GenerateStaticPageArtifactsAction
{
    use AsObject;

    public function __construct(
        private readonly Kernel $kernel,
        private readonly StaticPageArtifactStore $store,
        private readonly StaticPageArtifactPathResolver $pathResolver,
    ) {}

    /**
     * @param  array<int, string>  $urls
     * @return array<string, mixed>
     */
    public function handle(?int $siteId = null, array $urls = []): array
    {
        $artifacts = [];

        $this->pageUrls($siteId, $urls, function (PageUrl $pageUrl) use (&$artifacts): void {
            $siteDomain = $this->siteDomainFor($pageUrl);

            if (! $siteDomain instanceof SiteDomain) {
                return;
            }

            $this->clearRenderData();
            $response = $this->render($pageUrl, $siteDomain);
            $renderData = resolve(FrontendContextReader::class)->getFrontendData('publicPageRenderData');

            if (! $this->isWritableHtmlResponse($response)) {
                return;
            }

            if (! $renderData instanceof PublicPageRenderData) {
                $renderData = $this->buildRenderData($pageUrl);
            }

            if (! $renderData instanceof PublicPageRenderData) {
                return;
            }

            $file = $this->pathResolver->pathForPageUrl($pageUrl, $siteDomain);
            $this->store->putHtml($file, (string) $response->getContent());

            $artifacts[] = BuildStaticPageArtifactMetadataAction::run($pageUrl, $renderData, $response, $file)->toArray();
        });

        $manifest = [
            'generated_at' => Date::now()->toIso8601String(),
            'artifacts' => $artifacts,
        ];

        $this->store->writeManifest($manifest);

        return $manifest;
    }

    /**
     * @param  array<int, string>  $urls
     */
    private function pageUrls(?int $siteId, array $urls, callable $callback): void
    {
        PageUrl::query()
            ->with(['language', 'site.theme', 'pageable.layout', 'pageable.site.theme'])
            ->enabled()
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($urls !== [], fn (Builder $query): Builder => $query->whereIn('url', $urls))
            ->where('url', 'not like', '%*%')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('type')
                    ->orWhere('type', '!=', UrlTypeEnum::Redirect);
            })
            ->whereHasMorph('pageable', '*', fn (Builder $query): Builder => $query->publishedDate())
            ->orderBy('id')
            ->orderBy('site_id')
            ->orderBy('language_id')
            ->orderBy('url')
            ->lazyById()
            ->each(function (PageUrl $pageUrl) use ($callback): void {
                if ($pageUrl->pageable instanceof Pageable) {
                    $callback($pageUrl);
                }
            });
    }

    private function siteDomainFor(PageUrl $pageUrl): ?SiteDomain
    {
        return $pageUrl->siteDomain()
            ->enabled()
            ->orderByDesc('default')
            ->orderBy('id')
            ->first();
    }

    private function render(PageUrl $pageUrl, SiteDomain $siteDomain): Response
    {
        $url = rtrim($siteDomain->full_url, '/') . ($pageUrl->url);
        $request = Request::create($url, \Symfony\Component\HttpFoundation\Request::METHOD_GET);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    private function clearRenderData(): void
    {
        $context = resolve(FrontendContextReader::class);
        $context->setFrontendData('publicPageRenderData', null);
        $context->setFrontendData('runtimeManifest', null);
        $context->setFrontendData('assetManifest', null);
        $context->setFrontendData('mediaHints', null);
        $context->setFrontendData('lcpMediaUrl', null);
        $context->setFrontendData('publicHtmlSafetyInspected', null);
        $context->setFrontendData('publicHtmlSafetyInspectedHash', null);
    }

    private function isWritableHtmlResponse(Response $response): bool
    {
        if ($response->getStatusCode() < Response::HTTP_OK || $response->getStatusCode() >= Response::HTTP_MULTIPLE_CHOICES) {
            return false;
        }

        $contentType = (string) $response->headers->get('content-type', 'text/html');

        if (! str_contains($contentType, 'text/html') || $response->headers->get('X-Capell-Public-Html-Safety') !== null) {
            return false;
        }

        $content = $response->getContent();

        if (is_string($content)
            && resolve(FrontendContextReader::class)->getFrontendData('publicHtmlSafetyInspected') === true
            && resolve(FrontendContextReader::class)->getFrontendData('publicHtmlSafetyInspectedHash') === hash('xxh128', $content)) {
            return true;
        }

        if (! is_string($content)) {
            return true;
        }

        try {
            AssertPublicRenderContractAction::run($response);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function buildRenderData(PageUrl $pageUrl): ?PublicPageRenderData
    {
        $page = $pageUrl->pageable;

        if (! $page instanceof Pageable) {
            return null;
        }

        $site = $pageUrl->site;
        $language = $pageUrl->language;
        $layout = $page instanceof Page ? $page->layout : null;
        $theme = $site?->theme;

        $context = new FrontendRenderContextData(
            page: $page,
            site: $site,
            language: $language,
            layout: $layout,
            theme: $theme,
        );
        $contextReader = $this->contextReaderFor($page, $site, $language, $layout, $theme);
        $context->runtimeManifest = ResolveFrontendRuntimeAction::run($contextReader)->runtimeManifest;

        return BuildPublicPageRenderDataAction::run($context);
    }

    private function contextReaderFor(
        Pageable $page,
        ?Site $site,
        ?Language $language,
        ?Layout $layout,
        ?Theme $theme,
    ): FrontendContextReader {
        return new class($page, $site, $language, $layout, $theme) implements FrontendContextReader
        {
            /**
             * @var array<string, mixed>
             */
            private array $data = [];

            public function __construct(
                private readonly Pageable $page,
                private readonly ?Site $site,
                private readonly ?Language $language,
                private readonly ?Layout $layout,
                private readonly ?Theme $theme,
            ) {}

            public function site(): ?Site
            {
                return $this->site;
            }

            public function language(): ?Language
            {
                return $this->language;
            }

            public function page(): Pageable
            {
                return $this->page;
            }

            public function layout(): ?Layout
            {
                return $this->layout;
            }

            public function theme(): ?Theme
            {
                return $this->theme;
            }

            public function params(): array
            {
                return [];
            }

            public function slug(): ?string
            {
                return null;
            }

            public function isError(): bool
            {
                return false;
            }

            public function setFrontendData(string $key, mixed $value): self
            {
                $this->data[$key] = $value;

                return $this;
            }

            public function getFrontendData(?string $key = null): mixed
            {
                if ($key === null) {
                    return $this->data;
                }

                return $this->data[$key] ?? null;
            }
        };
    }
}
