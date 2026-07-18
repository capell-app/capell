<?php

declare(strict_types=1);

namespace Capell\Core\Support\Links;

use Capell\Core\Contracts\LinkableContent;
use Capell\Core\Data\LinkableContentData;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PageLinkableContentProvider implements LinkableContent
{
    public function key(): string
    {
        return 'pages';
    }

    /**
     * @return Collection<int, LinkableContentData>
     */
    public function options(?int $siteId = null, ?int $languageId = null): Collection
    {
        return PageUrl::query()
            ->with(['pageable', 'translation'])
            ->where('pageable_type', (new Page)->getMorphClass())
            ->whereNull('type')
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($languageId !== null, fn (Builder $query): Builder => $query->where('language_id', $languageId))
            ->orderBy('url')
            ->get()
            ->map(fn (PageUrl $pageUrl): LinkableContentData => new LinkableContentData(
                type: 'page_url',
                id: $pageUrl->id,
                label: $this->label($pageUrl),
                url: $pageUrl->url,
                status: $pageUrl->status,
                site_id: $pageUrl->site_id,
                language_id: $pageUrl->language_id,
            ))
            ->values();
    }

    private function label(PageUrl $pageUrl): string
    {
        if ($pageUrl->translation instanceof Translation) {
            return $pageUrl->translation->link_text ?? $pageUrl->url;
        }

        $page = $pageUrl->pageable;

        if ($page instanceof Page) {
            return $page->name;
        }

        return $pageUrl->url;
    }
}
