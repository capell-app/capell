<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Concerns\HasCache;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\PublicContentWidgetPayloadBuilder;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\CacheEnum;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;

final class PublicPageRenderDataCache
{
    use HasCache;

    /**
     * @var array<string, array<int, string>>
     */
    private array $cacheKeysByIdentity = [];

    /**
     * @param  Closure(): PublicPageRenderData  $builder
     */
    public function remember(FrontendRenderContextData $context, Closure $builder): PublicPageRenderData
    {
        $key = $this->keyForContext($context);

        if ($key === null || config('capell-frontend.public_render_data_cache') !== true) {
            return $builder();
        }

        $this->rememberKeyForIdentity($context, $key);

        $renderData = $this->rememberCache($key, $builder);

        if ($renderData instanceof PublicPageRenderData) {
            return $renderData;
        }

        $renderData = $builder();
        $this->setToCache($key, $renderData);

        return $renderData;
    }

    public function invalidate(string $pageType, int $pageId, int $siteId, int $languageId): void
    {
        $identity = $this->identity($pageType, $pageId, $siteId, $languageId);

        foreach ($this->cacheKeysByIdentity[$identity] ?? [] as $key) {
            $this->removeCacheKey($key);
        }

        unset($this->cacheKeysByIdentity[$identity]);

        $this->incrementCacheKey(CacheEnum::publicRenderDataGeneration($pageType, $pageId, $siteId, $languageId));
    }

    public function keyForContext(FrontendRenderContextData $context): ?string
    {
        $page = $context->page;

        if (! $page instanceof Model || ! $page instanceof Pageable) {
            return null;
        }

        if (! $context->site instanceof Site || ! $context->language instanceof Language || ! $context->runtimeManifest instanceof FrontendRuntimeManifestData) {
            return null;
        }

        return CacheEnum::publicRenderData(
            pageType: $page::class,
            pageId: (int) $page->getKey(),
            siteId: (int) $context->site->getKey(),
            languageId: (int) $context->language->getKey(),
            renderingStrategy: $context->runtimeManifest->renderingStrategy->value,
            contentVersion: $this->contentVersion($context) . '-gen-' . $this->currentGeneration(
                $page::class,
                (int) $page->getKey(),
                (int) $context->site->getKey(),
                (int) $context->language->getKey(),
            ),
        );
    }

    private function contentVersion(FrontendRenderContextData $context): string
    {
        $values = [
            $context->page instanceof Model ? $context->page->updated_at?->getTimestamp() : null,
            $this->translationTimestamp($context->page instanceof Model ? $context->page : null),
            $context->layout?->updated_at?->getTimestamp(),
            $this->translationTimestamp($context->layout),
            $context->theme?->updated_at?->getTimestamp(),
            $this->translationTimestamp($context->theme),
            $context->site?->updated_at?->getTimestamp(),
            $this->translationTimestamp($context->site),
            $this->payloadBuilderFingerprint(),
        ];

        return hash('xxh128', implode('|', array_map(
            fn (mixed $value): string => (string) ($value ?? '0'),
            $values,
        )));
    }

    private function payloadBuilderFingerprint(): string
    {
        if (! app()->bound(PublicContentWidgetPayloadBuilder::class)) {
            return 'no-content-widget-payload-builder';
        }

        return resolve(PublicContentWidgetPayloadBuilder::class)->fingerprint();
    }

    private function translationTimestamp(?Model $model): ?int
    {
        $translation = $model?->relationLoaded('translation') === true
            ? $model->getRelation('translation')
            : null;
        $updatedAt = $translation instanceof Model ? $translation->getAttribute('updated_at') : null;

        return $updatedAt instanceof CarbonInterface ? $updatedAt->getTimestamp() : null;
    }

    private function rememberKeyForIdentity(FrontendRenderContextData $context, string $key): void
    {
        $page = $context->page;

        if (! $page instanceof Model || ! $page instanceof Pageable || ! $context->site instanceof Site || ! $context->language instanceof Language) {
            return;
        }

        $identity = $this->identity($page::class, (int) $page->getKey(), (int) $context->site->getKey(), (int) $context->language->getKey());
        $this->cacheKeysByIdentity[$identity] ??= [];

        if (! in_array($key, $this->cacheKeysByIdentity[$identity], true)) {
            $this->cacheKeysByIdentity[$identity][] = $key;
        }
    }

    private function identity(string $pageType, int $pageId, int $siteId, int $languageId): string
    {
        return sprintf('%s:%d:%d:%d', $pageType, $pageId, $siteId, $languageId);
    }

    private function currentGeneration(string $pageType, int $pageId, int $siteId, int $languageId): int
    {
        $generation = $this->getFromCache(CacheEnum::publicRenderDataGeneration($pageType, $pageId, $siteId, $languageId));

        return is_int($generation) ? $generation : 0;
    }
}
