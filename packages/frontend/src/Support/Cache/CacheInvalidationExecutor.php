<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Concerns\HasCache;
use Capell\Frontend\Data\CacheInvalidationPlanData;
use Capell\Frontend\Data\CacheInvalidationRule;

final class CacheInvalidationExecutor
{
    use HasCache;

    public function execute(CacheInvalidationPlanData $plan): void
    {
        foreach ($plan->rules as $rule) {
            if ($rule->kind === CacheInvalidationRule::KIND_FLUSH_FRONTEND_TAG) {
                $this->flushCache();
                $this->flushResolvedFrontendCacheState();

                return;
            }

            if ($rule->kind === CacheInvalidationRule::KIND_FORGET_KEY && $rule->cacheKey !== null) {
                $this->removeCacheKey($rule->cacheKey);
            }

            if ($rule->kind === CacheInvalidationRule::KIND_INVALIDATE_PATTERN && $rule->cacheKey !== null) {
                $this->invalidateCachePattern($rule->cacheKey);
            }

            if (
                $rule->kind === CacheInvalidationRule::KIND_PAGE_MODEL
                && $rule->modelType !== null
                && $rule->modelId !== null
                && $rule->siteId !== null
                && $rule->languageId !== null
            ) {
                resolve(PageModelCache::class)->invalidate($rule->modelType, $rule->modelId, $rule->siteId, $rule->languageId);
            }

            if (
                $rule->kind === CacheInvalidationRule::KIND_PAGE_LISTING
                && $rule->siteId !== null
                && $rule->languageId !== null
            ) {
                resolve(PageListingCache::class)->invalidateListings($rule->siteId, $rule->languageId);
            }

            if (
                $rule->kind === CacheInvalidationRule::KIND_PUBLIC_RENDER_DATA
                && $rule->modelType !== null
                && $rule->modelId !== null
                && $rule->siteId !== null
                && $rule->languageId !== null
            ) {
                resolve(PublicPageRenderDataCache::class)->invalidate($rule->modelType, $rule->modelId, $rule->siteId, $rule->languageId);
            }
        }
    }

    private function flushResolvedFrontendCacheState(): void
    {
        foreach ([PageListingCache::class, PageModelCache::class, PublicPageRenderDataCache::class] as $cacheClass) {
            if (! app()->resolved($cacheClass)) {
                continue;
            }

            $cache = resolve($cacheClass);

            if (method_exists($cache, 'flushLocalCache')) {
                $cache->flushLocalCache();
            }
        }
    }
}
