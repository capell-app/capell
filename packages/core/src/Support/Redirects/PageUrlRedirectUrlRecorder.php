<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\RedirectUrlRecorder;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;

final class PageUrlRedirectUrlRecorder implements RedirectUrlRecorder
{
    public function record(Pageable $pageable, Language $language, string $url): void
    {
        $siteId = $pageable->site_id;

        if (PageUrl::query()
            ->where('site_id', $siteId)
            ->where('language_id', $language->getKey())
            ->where('url', $url)
            ->exists()) {
            return;
        }

        $targetUrl = PageUrl::query()
            ->where('pageable_type', $pageable->getMorphClass())
            ->where('pageable_id', $pageable->getKey())
            ->where('language_id', $language->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->value('url');

        PageUrl::query()->create([
            'site_id' => $siteId,
            'language_id' => $language->getKey(),
            'pageable_type' => $pageable->getMorphClass(),
            'pageable_id' => $pageable->getKey(),
            'url' => $url,
            'target_url' => is_string($targetUrl) ? $targetUrl : null,
            'type' => UrlTypeEnum::Redirect,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'is_manual' => false,
            'status' => true,
        ]);
    }
}
