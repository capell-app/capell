<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Capell\Admin\Data\RecordRelationshipCountData;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Core\Models\Page;
use Spatie\LaravelData\Data;

final class PageRelationshipCountsData extends Data
{
    public function __construct(
        public readonly int $childrenCount,
        public readonly int $urlCount,
        public readonly ?string $pageUrlsUrl = null,
    ) {}

    public static function fromPage(Page $page): self
    {
        $attributes = $page->getAttributes();
        $childrenCount = is_numeric($attributes['children_count'] ?? null)
            ? (int) $attributes['children_count']
            : ($page->relationLoaded('children') ? $page->children->count() : $page->children()->count());
        $urlCount = is_numeric($attributes['page_urls_count'] ?? null)
            ? (int) $attributes['page_urls_count']
            : ($page->relationLoaded('pageUrls') ? $page->pageUrls->count() : $page->pageUrls()->count());

        return new self(
            childrenCount: $childrenCount,
            urlCount: $urlCount,
            pageUrlsUrl: $urlCount > 0 ? PageUrlResource::getUrl('index', [
                'filters[pageable][pageable_type]' => $page->getMorphClass(),
                'filters[pageable][pageable_id]' => $page->getKey(),
            ]) : null,
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
                url: $this->pageUrlsUrl,
            ),
        ];
    }
}
