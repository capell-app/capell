<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class InvalidateDueScheduledPublicationCachesAction
{
    use AsFake;
    use AsObject;

    public const string CHECKPOINT_CACHE_KEY = 'capell:frontend:scheduled-publication-invalidation-checkpoint';

    public function handle(?CarbonImmutable $until = null): int
    {
        $until ??= CarbonImmutable::now();
        $from = $this->checkpoint($until);
        $invalidated = 0;

        Page::query()
            ->with(['languages', 'translations'])
            ->where(function (Builder $query) use ($from, $until): void {
                $query
                    ->where(function (Builder $publishQuery) use ($from, $until): void {
                        $publishQuery
                            ->where('visible_from', '>', $from)
                            ->where('visible_from', '<=', $until);
                    })
                    ->orWhere(function (Builder $unpublishQuery) use ($from, $until): void {
                        $unpublishQuery
                            ->where('visible_until', '>', $from)
                            ->where('visible_until', '<=', $until);
                    });
            })
            ->chunkById(100, function ($pages) use (&$invalidated): void {
                foreach ($pages as $page) {
                    resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($page);
                    event(new FrontendSurrogateKeysInvalidated($this->surrogateKeys($page)));
                    $invalidated++;
                }
            });

        Cache::forever(self::CHECKPOINT_CACHE_KEY, $until->getTimestamp());

        return $invalidated;
    }

    private function checkpoint(CarbonImmutable $until): CarbonImmutable
    {
        $checkpoint = Cache::get(self::CHECKPOINT_CACHE_KEY);

        if (! is_int($checkpoint)) {
            return $until->subMinutes(2);
        }

        $from = $until->setTimestamp($checkpoint);

        return $from->lessThan($until) ? $from : $until->subMinutes(2);
    }

    /**
     * @return list<string>
     */
    private function surrogateKeys(Page $page): array
    {
        return [
            'page-' . $page->getKey(),
            'site-' . $page->site_id,
            ...$page->languages
                ->map(fn (Language $language): string => 'lang-' . $language->code)
                ->all(),
        ];
    }
}
