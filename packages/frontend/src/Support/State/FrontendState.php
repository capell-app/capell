<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\State;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendContext;

final class FrontendState implements FrontendContextReader
{
    public function __construct(
        private ?Site $site = null,
        private ?Language $language = null,
        private ?Pageable $page = null,
        private ?Layout $layout = null,
        private ?Theme $theme = null,
        private array $params = [],
        private ?string $slug = null,
        private ?string $relativePath = null,
        private ?string $effectiveUrl = null,
        private ?int $revisionPageId = null,
        private bool $isError = false,
        private ?SiteDomain $domain = null,
        /** @var array<string,mixed> */
        private array $data = [],
    ) {}

    public function reset(): self
    {
        $this->site = null;
        $this->language = null;
        $this->page = null;
        $this->layout = null;
        $this->theme = null;
        $this->params = [];
        $this->slug = null;
        $this->relativePath = null;
        $this->effectiveUrl = null;
        $this->revisionPageId = null;
        $this->isError = false;
        $this->domain = null;
        $this->data = [];

        return $this;
    }

    public function snapshot(): FrontendContext
    {
        return new FrontendContext(
            site: $this->site,
            language: $this->language,
            page: $this->page,
            layout: $this->layout,
            theme: $this->theme,
            params: $this->params,
            slug: $this->slug,
            isError: $this->isError(),
        );
    }

    public function site(): ?Site
    {
        return $this->site;
    }

    public function language(): ?Language
    {
        return $this->language;
    }

    public function page(): ?Pageable
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

    /** @return array<string,mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function slug(): ?string
    {
        return $this->slug;
    }

    public function isError(): bool
    {
        return $this->isError || ($this->page instanceof Page && $this->page->isErrorPage());
    }

    public function markAsError(bool $isError = true): self
    {
        $this->isError = $isError;

        return $this;
    }

    public function relativePath(): ?string
    {
        return $this->relativePath;
    }

    public function effectiveUrl(): ?string
    {
        return $this->effectiveUrl;
    }

    public function revisionPageId(): ?int
    {
        return $this->revisionPageId;
    }

    public function domain(): ?SiteDomain
    {
        return $this->domain;
    }

    public function baseUrl(): ?string
    {
        return $this->domain?->full_url;
    }

    public function rootUrl(): ?string
    {
        return $this->domain?->root_url;
    }

    public function withSite(Site $site): self
    {
        $this->site = $site;

        return $this;
    }

    public function withLanguage(Language $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function withPage(Pageable $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function withLayout(Layout $layout): self
    {
        $this->layout = $layout;

        return $this;
    }

    public function withTheme(Theme $theme): self
    {
        $this->theme = $theme;

        return $this;
    }

    public function withParams(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    public function withSlug(?string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function withRelativePath(?string $relativePath): self
    {
        $this->relativePath = $relativePath;

        return $this;
    }

    public function setEffectiveUrl(?string $url): self
    {
        $this->effectiveUrl = $url;

        return $this;
    }

    public function setRevisionPageId(?int $pageId): self
    {
        $this->revisionPageId = $pageId;

        return $this;
    }

    public function withDomain(SiteDomain $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    /**
     * Store arbitrary frontend data in scoped state for this request.
     */
    public function setFrontendData(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Retrieve arbitrary frontend data from scoped state; when key is null, return full map.
     *
     * @return mixed|array<string,mixed>
     */
    public function getFrontendData(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }
}
