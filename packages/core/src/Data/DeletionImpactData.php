<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class DeletionImpactData extends Data
{
    public function __construct(
        public readonly int $pages = 0,
        public readonly int $siteDomains = 0,
        public readonly int $layouts = 0,
        public readonly int $pageUrls = 0,
        public readonly int $translations = 0,
    ) {}

    public function add(self $impact): self
    {
        return new self(
            pages: $this->pages + $impact->pages,
            siteDomains: $this->siteDomains + $impact->siteDomains,
            layouts: $this->layouts + $impact->layouts,
            pageUrls: $this->pageUrls + $impact->pageUrls,
            translations: $this->translations + $impact->translations,
        );
    }

    public function total(): int
    {
        return $this->pages
            + $this->siteDomains
            + $this->layouts
            + $this->pageUrls
            + $this->translations;
    }
}
