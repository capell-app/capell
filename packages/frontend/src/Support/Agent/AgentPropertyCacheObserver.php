<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Agent;

use Capell\Core\Enums\TranslatableType;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Capell\Core\Models\Translation;
use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Illuminate\Database\Eloquent\Model;

/** Newly added children have no cache receipts yet; invalidate their recorded owner. */
final class AgentPropertyCacheObserver
{
    public function saved(Model $model): void
    {
        $owner = match (true) {
            $model instanceof PageUrl => $this->pageForUrl($model),
            $model instanceof Translation && $model->translatable_type === TranslatableType::Page->value => Page::query()->find($model->translatable_id),
            $model instanceof PagePropertyValue => Page::query()->whereKey($model->page_id)->where('site_id', $model->site_id)->first(),
            $model instanceof TermPropertyValue => Term::query()->find($model->term_id),
            $model instanceof PropertyDefinition => PropertySet::query()->find($model->property_set_id),
            $model instanceof BlueprintPropertySet => Blueprint::query()->find($model->blueprint_id),
            $model instanceof Term => Taxonomy::query()->find($model->taxonomy_id),
            $model instanceof PropertySet, $model instanceof Taxonomy => $model,
            default => null,
        };
        if ($owner instanceof Model) {
            resolve(CacheInvalidationRegistry::class)->invalidateChangedModel($owner);
        }
    }

    public function deleted(Model $model): void
    {
        $this->saved($model);
    }

    private function pageForUrl(PageUrl $url): ?Page
    {
        if ($url->pageable_type !== new Page()->getMorphClass()) {
            return null;
        }

        $page = Page::query()
            ->whereKey($url->pageable_id)
            ->where('site_id', $url->site_id)
            ->first();

        return $page instanceof Page ? $page : null;
    }
}
