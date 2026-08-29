<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\Redirects\CreateAutomaticRedirectAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Events\PageSaved;
use Capell\Core\Events\PageUrlsRewritten;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Builder;

class CreateRedirectsForChangedPageUrls
{
    public function handle(PageSaved $event): void
    {
        if (! config('redirects.auto_redirects.enabled', true)) {
            return;
        }

        $previousUrls = $event->formData['_previous_urls'] ?? [];

        if (! is_array($previousUrls) || $previousUrls === []) {
            return;
        }

        foreach ($previousUrls as $languageId => $oldUrl) {
            if (! is_string($oldUrl)) {
                continue;
            }

            if ($oldUrl === '') {
                continue;
            }

            $language = Language::query()->find((int) $languageId);
            $currentUrl = $event->page->pageUrls()
                ->where('language_id', (int) $languageId)
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('type')
                        ->orWhere('type', '!=', UrlTypeEnum::Redirect);
                })
                ->value('url');
            if (! $language instanceof Language) {
                continue;
            }

            if (! is_string($currentUrl)) {
                continue;
            }

            CreateAutomaticRedirectAction::run($event->page, $language, $oldUrl, $currentUrl);
        }
    }

    public function handleUrlRewrite(PageUrlsRewritten $event): void
    {
        if (! $event->automaticRedirectsAllowed || ! config('redirects.auto_redirects.enabled', true)) {
            return;
        }

        $pageChanges = [
            (int) $event->page->getKey() => $event->urlChanges,
        ];
        $pages = [(int) $event->page->getKey() => $event->page];

        if ($event->descendantUrlChanges !== []) {
            $descendants = $event->page->newQuery()
                ->whereKey(array_keys($event->descendantUrlChanges))
                ->get();

            foreach ($descendants as $descendant) {
                if (! $descendant instanceof Pageable) {
                    continue;
                }

                $pageId = (int) $descendant->getKey();
                $pages[$pageId] = $descendant;
                $pageChanges[$pageId] = $event->descendantUrlChanges[$pageId] ?? [];
            }
        }

        $languageIds = [];

        foreach ($pageChanges as $changes) {
            foreach (array_keys($changes) as $languageId) {
                $languageIds[(int) $languageId] = true;
            }
        }

        $languages = Language::query()
            ->whereIn('id', array_keys($languageIds))
            ->get()
            ->keyBy(fn (Language $language): int => (int) $language->getKey());

        foreach ($pageChanges as $pageId => $changes) {
            $page = $pages[$pageId] ?? null;

            if (! $page instanceof Pageable) {
                continue;
            }

            foreach ($changes as $languageId => $change) {
                $language = $languages->get((int) $languageId);

                if (! $language instanceof Language) {
                    continue;
                }

                CreateAutomaticRedirectAction::run($page, $language, $change['old'], $change['new']);
            }
        }
    }
}
