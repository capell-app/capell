<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\Redirects\CreateAutomaticRedirectAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Events\PageSaved;
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
}
