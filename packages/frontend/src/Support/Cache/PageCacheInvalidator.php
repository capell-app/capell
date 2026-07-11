<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Contracts\Pageable;
use Capell\Frontend\Data\CacheInvalidationPlanData;
use Capell\Frontend\Data\CacheInvalidationRule;
use Illuminate\Database\Eloquent\Model;

final class PageCacheInvalidator
{
    public function __construct(
        private readonly CacheInvalidationExecutor $executor,
    ) {}

    /**
     * Called after a Pageable model is created, updated, or deleted.
     * Invalidates both the individual model caches and the listing ID caches
     * for all languages the model has a translation in.
     */
    public function invalidate(Model&Pageable $model): void
    {
        $this->onSaved($model);
    }

    public function onSaved(Model&Pageable $model): void
    {
        $this->executor->execute($this->planForPage($model));
    }

    public function planForPage(Model&Pageable $model): CacheInvalidationPlanData
    {
        $siteId = (int) $model->getAttribute('site_id');
        $type = $model::class;
        $id = (int) $model->getKey();
        $rules = [];

        // Invalidate individual model cache for every language this model lives in
        $model->translations->each(function ($translation) use (&$rules, $type, $id, $siteId): void {
            $languageId = (int) $translation->getAttribute('language_id');
            $rules[] = CacheInvalidationRule::pageModel($type, $id, $siteId, $languageId);
            $rules[] = CacheInvalidationRule::pageListing($siteId, $languageId);
            $rules[] = CacheInvalidationRule::publicRenderData($type, $id, $siteId, $languageId);
        });

        return new CacheInvalidationPlanData($rules);
    }
}
