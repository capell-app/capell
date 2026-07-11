<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Spatie\LaravelData\Data;

class FrontendContext extends Data implements FrontendContextReader
{
    public function __construct(
        public ?Site $site,
        public ?Language $language,
        public ?Pageable $page,
        public ?Layout $layout,
        public ?Theme $theme,
        /** @var array<string,mixed> */
        public array $params,
        public ?string $slug,
        public bool $isError = false,
    ) {}

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
        if ($this->isError) {
            return true;
        }

        if ($this->page instanceof Page) {
            return $this->page->isErrorPage();
        }

        return false;
    }

    public function setFrontendData(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    public function getFrontendData(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->params;
        }

        return $this->params[$key] ?? null;
    }
}
