<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\CapellCoreHelper;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PageUrlObserver
{
    public function saving(PageUrl $pageUrl): void
    {
        if (! $pageUrl->status) {
            return;
        }

        $duplicateExists = PageUrl::query()
            ->where('site_id', $pageUrl->site_id)
            ->where('language_id', $pageUrl->language_id)
            ->where('url', $pageUrl->url)
            ->where('status', true)
            ->when($pageUrl->exists, fn (Builder $query): Builder => $query->whereKeyNot($pageUrl->getKey()))
            ->exists();

        throw_if(
            $duplicateExists,
            Exception::class,
            sprintf(
                'Page URL "%s" already exists for site ID %d and language ID %d.',
                $pageUrl->url,
                $pageUrl->site_id,
                $pageUrl->language_id,
            ),
        );
    }

    public function creating(PageUrl $pageUrl): void
    {
        if ($pageUrl->is_manual) {
            return;
        }

        /** @var (Model&Pageable<Model>)|null $page */
        $page = $pageUrl->relationLoaded('page')
            ? $pageUrl->getRelation('page')
            : $this->pageRelation($pageUrl)->first();

        throw_if($page === null, Exception::class, 'Page not found. The Url must be associated with a valid Page.');

        throw_if($pageUrl->site_id !== $page->site_id, Exception::class, 'Site ID mismatch. The PageUrl(site_id=' . $pageUrl->site_id . ') must match the Page(site_id=' . $page->site_id . ').');
    }

    public function saved(PageUrl $pageUrl): void
    {
        CapellCoreHelper::flushCache([
            CacheEnum::FirstPageByTypeForSite->value,
            CacheEnum::RelationExists->value,
        ]);

        $this->dispatchPageUrlChangedEvent($pageUrl);
    }

    public function deleted(PageUrl $pageUrl): void
    {
        $this->saved($pageUrl);
    }

    /**
     * @return MorphTo<Model, PageUrl>
     */
    private function pageRelation(PageUrl $pageUrl): MorphTo
    {
        return $pageUrl->pageable();
    }

    private function dispatchPageUrlChangedEvent(PageUrl $pageUrl): void
    {
        if (! $pageUrl->wasChanged(['url', 'target_url'])) {
            return;
        }

        event(new PageUrlChanged(
            page_url_id: (int) $pageUrl->getKey(),
            page_id: $pageUrl->pageable_type === resolve(Page::class)->getMorphClass()
                ? $pageUrl->pageable_id
                : null,
            site_id: $pageUrl->site_id,
            language_id: $pageUrl->language_id,
            old_url: (string) $pageUrl->getOriginal('url'),
            new_url: $pageUrl->url,
        ));
    }
}
