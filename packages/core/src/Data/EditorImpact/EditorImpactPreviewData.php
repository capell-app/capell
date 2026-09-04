<?php

declare(strict_types=1);

namespace Capell\Core\Data\EditorImpact;

use Capell\Core\Support\Impact\ImpactPlanFingerprint;
use Spatie\LaravelData\Data;

final class EditorImpactPreviewData extends Data
{
    public readonly string $fingerprint;

    /**
     * @param  list<EditorImpactPageData>  $pages
     */
    public function __construct(
        public readonly int $pageCount,
        public readonly int $siteCount,
        public readonly int $localeCount,
        public readonly array $pages,
        ?string $fingerprint = null,
    ) {
        $this->fingerprint = $fingerprint ?? ImpactPlanFingerprint::forPlan($this->planPayload());
    }

    public function withFingerprint(string $fingerprint): self
    {
        return new self(
            pageCount: $this->pageCount,
            siteCount: $this->siteCount,
            localeCount: $this->localeCount,
            pages: $this->pages,
            fingerprint: $fingerprint,
        );
    }

    /** @return list<string> */
    public function surfaceKeys(): array
    {
        $surfaces = [];

        foreach ($this->pages as $page) {
            foreach ($page->urls as $url) {
                $surfaces[] = 'url:' . $url->url;
            }

            if ($page->urls === []) {
                $surfaces[] = 'page:' . $page->type . '|' . $page->site . '|' . $page->name;
            }
        }

        sort($surfaces);

        return array_values(array_unique($surfaces));
    }

    /** @return array<string, mixed> */
    public function planPayload(): array
    {
        return [
            'pageCount' => $this->pageCount,
            'siteCount' => $this->siteCount,
            'localeCount' => $this->localeCount,
            'pages' => array_map(
                static fn (EditorImpactPageData $page): array => $page->toArray(),
                $this->pages,
            ),
        ];
    }
}
