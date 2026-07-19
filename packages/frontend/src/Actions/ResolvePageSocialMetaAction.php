<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\SocialMetaData;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** @method static SocialMetaData run(FrontendRenderContextData $context) */
final class ResolvePageSocialMetaAction
{
    use AsFake;
    use AsObject;

    public function handle(FrontendRenderContextData $context): SocialMetaData
    {
        $page = $context->page;
        $site = $context->site;
        $pageTranslation = $this->loadedRelation($page, 'translation');
        $siteTranslation = $this->loadedRelation($site, 'translation');
        $pageMeta = $this->meta($page);
        $siteMeta = $this->meta($site);

        $title = $this->firstString([
            data_get($this->meta($pageTranslation), 'title'),
            $this->safeValue($pageTranslation, 'title'),
            data_get($pageMeta, 'title'),
            $this->safeValue($page, 'name'),
            data_get($this->meta($siteTranslation), 'title'),
            $this->safeValue($siteTranslation, 'title'),
            $this->safeValue($site, 'name'),
        ]) ?? '';
        $description = $this->firstString([
            data_get($this->meta($pageTranslation), 'description'),
            $this->safeValue($pageTranslation, 'meta_description'),
            data_get($pageMeta, 'description'),
            data_get($pageMeta, 'meta_description'),
            data_get($this->meta($siteTranslation), 'description'),
            $this->safeValue($siteTranslation, 'meta_description'),
            data_get($siteMeta, 'description'),
            data_get($siteMeta, 'meta_description'),
        ]) ?? '';
        $canonicalUrl = $page instanceof Pageable && $context->language instanceof Language
            ? ResolvePageCanonicalUrlAction::run($page, $context->language)
            : null;
        $canonicalUrl ??= $this->firstString([
            $this->safeValue($this->loadedRelation($site, 'siteDomain'), 'full_url'),
        ]) ?? '';
        $imageUrl = $this->resolveImageUrl($pageTranslation, $pageMeta, $siteTranslation, $siteMeta);

        return new SocialMetaData(
            title: $title,
            description: $description,
            canonicalUrl: $canonicalUrl,
            imageUrl: $imageUrl,
            type: $this->blueprintKey($page) === 'article' ? 'article' : 'website',
            twitterCard: $imageUrl !== null ? 'summary_large_image' : 'summary',
        );
    }

    private function loadedRelation(mixed $model, string $relation): mixed
    {
        return $model instanceof Model && $model->relationLoaded($relation)
            ? $model->getRelation($relation)
            : null;
    }

    /** @return array<string, mixed> */
    private function meta(mixed $model): array
    {
        $meta = $this->safeValue($model, 'meta');

        return is_array($meta) ? $meta : [];
    }

    /** @param list<mixed> $values */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function safeValue(mixed $model, string $attribute): mixed
    {
        if (! is_object($model)) {
            return null;
        }

        try {
            return data_get($model, $attribute);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $pageMeta
     * @param  array<string, mixed>  $siteMeta
     */
    private function resolveImageUrl(mixed $pageTranslation, array $pageMeta, mixed $siteTranslation, array $siteMeta): ?string
    {
        $value = $this->firstString([
            data_get($this->meta($pageTranslation), 'social_image_url'),
            data_get($this->meta($pageTranslation), 'image_url'),
            data_get($pageMeta, 'social_image_url'),
            data_get($pageMeta, 'image_url'),
            data_get($pageMeta, 'image_source.url'),
            data_get($this->meta($siteTranslation), 'social_image_url'),
            data_get($siteMeta, 'social_image_url'),
            data_get($siteMeta, 'image_url'),
        ]);

        if ($value !== null) {
            return $value;
        }

        $path = $this->firstString([
            data_get($pageMeta, 'image_source.path'),
            data_get($siteMeta, 'image_source.path'),
        ]);

        return $path === null ? null : asset('storage/' . ltrim($path, '/'));
    }

    private function blueprintKey(mixed $page): ?string
    {
        $blueprint = $this->loadedRelation($page, 'blueprint');

        return $blueprint instanceof Blueprint ? $blueprint->key : null;
    }
}
