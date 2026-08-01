<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Admin\Data\RecordRelationshipCountData;
use Capell\Core\Models\Page;
use Spatie\LaravelData\Data;

final class PageRelationshipCountsData extends Data
{
    public function __construct(
        public readonly int $childrenCount,
        public readonly int $urlCount,
    ) {}

    public static function fromPage(Page $page): self
    {
        $childrenCount = is_numeric($page->getAttribute('children_count'))
            ? (int) $page->getAttribute('children_count')
            : ($page->relationLoaded('children') ? $page->children->count() : $page->children()->count());
        $urlCount = is_numeric($page->getAttribute('page_urls_count'))
            ? (int) $page->getAttribute('page_urls_count')
            : ($page->relationLoaded('pageUrls') ? $page->pageUrls->count() : $page->pageUrls()->count());

        return new self(
            childrenCount: $childrenCount,
            urlCount: $urlCount,
        );
    }

    /** @return list<RecordRelationshipCountData> */
    public function counts(): array
    {
        return [
            new RecordRelationshipCountData(
                key: 'children',
                label: (string) __('capell-admin::table.page_relationship_children'),
                count: $this->childrenCount,
            ),
            new RecordRelationshipCountData(
                key: 'urls',
                label: (string) __('capell-admin::table.page_relationship_urls'),
                count: $this->urlCount,
            ),
        ];
    }
}
