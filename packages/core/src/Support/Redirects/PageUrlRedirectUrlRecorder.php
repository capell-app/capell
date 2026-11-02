<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Contracts\Redirects\BatchRedirectUrlRecorder;
use Capell\Core\Data\Redirects\RedirectUrlRecordData;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Override;

final class PageUrlRedirectUrlRecorder implements BatchRedirectUrlRecorder
{
    private const int LOOKUP_CHUNK = 500;

    #[Override]
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

        $this->create($pageable, $language, $url, is_string($targetUrl) ? $targetUrl : null);
    }

    /**
     * Existing URLs are found with one lookup per site, language and chunk rather than one per redirect;
     * each redirect is still created through the model so PageUrl observers run.
     */
    #[Override]
    public function recordMany(array $records): void
    {
        $groups = [];

        foreach ($records as $record) {
            $groups[$record->pageable->site_id . ':' . $record->language->getKey()][] = $record;
        }

        foreach ($groups as $group) {
            $siteId = $group[0]->pageable->site_id;
            $languageId = $group[0]->language->getKey();
            $taken = [];

            foreach (array_chunk(array_values(array_unique(array_map(static fn (RedirectUrlRecordData $record): string => $record->url, $group))), self::LOOKUP_CHUNK) as $urls) {
                foreach (PageUrl::query()
                    ->where('site_id', $siteId)
                    ->where('language_id', $languageId)
                    ->whereIn('url', $urls)
                    ->pluck('url') as $existingUrl) {
                    $taken[(string) $existingUrl] = true;
                }
            }

            foreach ($group as $record) {
                if (isset($taken[$record->url])) {
                    continue;
                }

                if ($record->targetUrl === null) {
                    $this->record($record->pageable, $record->language, $record->url);
                } else {
                    $this->create($record->pageable, $record->language, $record->url, $record->targetUrl);
                }

                $taken[$record->url] = true;
            }
        }
    }

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $pageable
     */
    private function create(Pageable $pageable, Language $language, string $url, ?string $targetUrl): void
    {
        $pageUrl = PageUrl::query()->make([
            'site_id' => $pageable->site_id,
            'language_id' => $language->getKey(),
            'pageable_type' => $pageable->getMorphClass(),
            'pageable_id' => $pageable->getKey(),
            'url' => $url,
            'target_url' => $targetUrl,
            'type' => UrlTypeEnum::Redirect,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'is_manual' => false,
            'status' => true,
        ]);

        // The observer validates the owning page; hand it the page we already hold instead of reloading it.
        $pageUrl->setRelation('page', $pageable);
        $pageUrl->save();
    }
}
