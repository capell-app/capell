<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Exceptions\PageUrlSiteMismatchException;
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
        $this->assertOwnerBelongsToUrlSite($pageUrl);

        if (! $pageUrl->status) {
            return;
        }

        $duplicateExists = PageUrl::query()
            ->where('site_id', $pageUrl->site_id)
            ->where('language_id', $pageUrl->language_id)
            ->where('url', $pageUrl->url)
            ->enabled()
            ->when($pageUrl->exists, fn (Builder $query): Builder => $query->whereKeyNot($pageUrl->getKey()))
            ->when(
                $pageUrl->type === null && $pageUrl->pageable_id !== null && $pageUrl->pageable_type !== null,
                function (Builder $query) use ($pageUrl): void {
                    // Restoring a canonical path must not collide with this page's own redirects.
                    $query->where(function (Builder $query) use ($pageUrl): void {
                        $query->whereNull('pageable_id')
                            ->orWhereNull('pageable_type')
                            ->orWhere('pageable_id', '!=', $pageUrl->pageable_id)
                            ->orWhere('pageable_type', '!=', $pageUrl->pageable_type);
                    });
                },
            )
            ->exists();

        if ($duplicateExists) {
            throw new PageUrlCollisionException(
                url: $pageUrl->url,
                siteId: $pageUrl->site_id,
                languageId: $pageUrl->language_id,
            );
        }
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
     * A URL answers for the site it belongs to, so the page it points at must belong to
     * that site too. Manual and disabled URLs follow the same rule, and it is checked on
     * every write that sets the site or the target, not only on creation.
     */
    private function assertOwnerBelongsToUrlSite(PageUrl $pageUrl): void
    {
        if ($pageUrl->pageable_type === null || $pageUrl->pageable_id === null) {
            return;
        }

        if ($pageUrl->exists && ! $pageUrl->isDirty(['site_id', 'pageable_type', 'pageable_id'])) {
            return;
        }

        $owner = $this->loadedOwner($pageUrl) ?? $this->pageRelation($pageUrl)->withTrashed()->first();

        if (! $owner instanceof Model || ! $owner->hasAttribute('site_id') || $owner->getAttribute('site_id') === null) {
            return;
        }

        throw_if(
            (int) $owner->getAttribute('site_id') !== (int) $pageUrl->site_id,
            PageUrlSiteMismatchException::class,
            (int) $pageUrl->site_id,
            (int) $owner->getAttribute('site_id'),
        );
    }

    /**
     * Writers that already hold the page (the redirect recorder, relationship creates)
     * hand it over as a loaded relation, so the site check costs no query for them. A
     * relation that no longer matches the type and key being saved is ignored.
     */
    private function loadedOwner(PageUrl $pageUrl): ?Model
    {
        foreach (['pageable', 'page'] as $name) {
            $owner = $pageUrl->relationLoaded($name) ? $pageUrl->getRelation($name) : null;

            if ($owner instanceof Model
                && $owner->getMorphClass() === $pageUrl->pageable_type
                && (string) $owner->getKey() === (string) $pageUrl->pageable_id) {
                return $owner;
            }
        }

        return null;
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
