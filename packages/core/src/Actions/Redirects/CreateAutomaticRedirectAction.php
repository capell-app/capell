<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateAutomaticRedirectAction
{
    use AsObject;

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    public function handle(Pageable $page, Language $language, string $oldUrl, string $currentUrl): bool
    {
        if ($oldUrl === $currentUrl) {
            return false;
        }

        if ($this->hasManualRedirect($page, $language, $oldUrl)) {
            return false;
        }

        if ($this->hasConflictingActiveSource($page, $language, $oldUrl)) {
            return false;
        }

        $page->pageUrls()->updateOrCreate(
            [
                'language_id' => $language->getKey(),
                'site_id' => $page->site_id,
                'url' => $oldUrl,
                'type' => UrlTypeEnum::Redirect,
                'is_manual' => false,
            ],
            [
                'status' => true,
                'status_code' => $this->configuredStatusCode(),
            ],
        );

        return true;
    }

    private function configuredStatusCode(): RedirectStatusCodeEnum
    {
        $statusCode = config('redirects.auto_redirects.status_code', RedirectStatusCodeEnum::Permanent->value);

        return RedirectStatusCodeEnum::tryFrom($statusCode) ?? RedirectStatusCodeEnum::Permanent;
    }

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function hasManualRedirect(Pageable $page, Language $language, string $oldUrl): bool
    {
        return PageUrl::query()
            ->where('language_id', $language->getKey())
            ->where('site_id', $page->site_id)
            ->where('url', $oldUrl)
            ->where('type', UrlTypeEnum::Redirect)
            ->where('is_manual', true)
            ->exists();
    }

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function hasConflictingActiveSource(Pageable $page, Language $language, string $oldUrl): bool
    {
        return PageUrl::query()
            ->where('language_id', $language->getKey())
            ->where('site_id', $page->site_id)
            ->where('url', $oldUrl)
            ->where('status', true)
            ->where(function (Builder $query) use ($page): void {
                $query
                    ->whereNull('type')
                    ->orWhere('type', '!=', UrlTypeEnum::Redirect)
                    ->orWhere('is_manual', true)
                    ->orWhere('pageable_type', '!=', $page->getMorphClass())
                    ->orWhere('pageable_id', '!=', $page->getKey());
            })
            ->exists();
    }
}
