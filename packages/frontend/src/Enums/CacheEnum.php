<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum CacheEnum: string
{
    case Languages = 'languages';

    case Navigations = 'navigations';

    case Sites = 'sites';

    case Pages = 'pages';

    case PageMedia = 'page-media';

    /**
     * Generate a custom cache key with a prefix.
     *
     * @param  string  $key  The key
     * @param  string  $prefix  The prefix (optional)
     * @return string The cache key
     */
    public static function custom(string $key, string $prefix = 'frontend'): string
    {
        return $prefix . '.' . $key;
    }

    /**
     * Generate a cache key for site with language.
     *
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function site(int $siteId, int $languageId): string
    {
        return sprintf('site-%d-%d', $siteId, $languageId);
    }

    /**
     * Generate a cache key for site media.
     *
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function siteMedia(int $siteId, int $languageId): string
    {
        return sprintf('site-media-%d-language-%d', $siteId, $languageId);
    }

    /**
     * Generate a cache key for site page relation.
     *
     * @param  string  $relation  The relation name
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function sitePage(string $relation, int $siteId, int $languageId): string
    {
        return sprintf('site-%s-page-%d-language-%d', $relation, $siteId, $languageId);
    }

    /**
     * Generate a cache key for page languages.
     *
     * @param  int  $pageId  The page ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function pageLanguages(int $pageId, int $languageId): string
    {
        return sprintf('page-languages-%d-language-%d', $pageId, $languageId);
    }

    /**
     * Generate a cache key for related sites.
     *
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function siteRelated(int $siteId, int $languageId): string
    {
        return sprintf('site-related-%d-language-%d', $siteId, $languageId);
    }

    /**
     * Generate a cache key for canonical pages.
     *
     * @param  int  $pageId  The page ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function pageCanonicals(int $pageId, int $languageId): string
    {
        return sprintf('page-canonicals-%d-%d', $pageId, $languageId);
    }

    /**
     * Generate a cache key for error page.
     *
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function pageError(int $siteId, int $languageId): string
    {
        return self::systemPage('error', $siteId, $languageId);
    }

    public static function systemPage(string $type, int $siteId, int $languageId): string
    {
        return sprintf('system-page-%s-%d-%d', $type, $siteId, $languageId);
    }

    /**
     * Generate a cache key for home page.
     *
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function homePage(int $siteId, int $languageId): string
    {
        return sprintf('homepage-%d-%d', $siteId, $languageId);
    }

    /**
     * Generate a cache key for next page.
     *
     * @param  int  $pageId  The page ID
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function pageNext(string $pageType, int $pageId, int $siteId, int $languageId): string
    {
        return sprintf('page-next-%s-%d-site-%d-lang-%d', $pageType, $pageId, $siteId, $languageId);
    }

    /**
     * Generate a cache key for page ancestors.
     *
     * @param  int  $pageId  The page ID
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function pageAncestors(int $pageId, int $siteId, int $languageId): string
    {
        return sprintf('page-ancestors-%d-site-%d-language-%d', $pageId, $siteId, $languageId);
    }

    public static function pageByUrl(string $urlKey, int $siteId, int $languageId, ?string $pageType = null, ?int $pageId = null): string
    {
        return sprintf('page-url-%s-site-%d-lang-%d-page-%s-%d', $urlKey, $siteId, $languageId, $pageType, $pageId);
    }

    public static function publicRenderData(
        string $pageType,
        int $pageId,
        int $siteId,
        int $languageId,
        string $renderingStrategy,
        string $contentVersion,
    ): string {
        return sprintf(
            'public-render-data-%s-%d-site-%d-lang-%d-strategy-%s-version-%s',
            $pageType,
            $pageId,
            $siteId,
            $languageId,
            $renderingStrategy,
            $contentVersion,
        );
    }

    public static function publicRenderDataGeneration(string $pageType, int $pageId, int $siteId, int $languageId): string
    {
        return sprintf(
            'public-render-data-generation-%s-%d-site-%d-lang-%d',
            $pageType,
            $pageId,
            $siteId,
            $languageId,
        );
    }

    /**
     * Generate a cache key for page media.
     *
     * @param  int  $pageId  The page ID
     * @return string The cache key
     */
    public static function pageMedia(int $pageId): string
    {
        return self::PageMedia->value . '-' . $pageId;
    }

    public static function pages(int $languageId, ?int $siteId, ?int $limit = null, array $options = []): string
    {
        $cacheKey = sprintf(self::Pages->value . '-%d-%d-limit-%s', $languageId, $siteId, $limit);

        if (isset($options['pageable_id'], $options['pageable_type']) && filled($options['pageable_type']) && filled($options['pageable_id'])) {
            $cacheKey .= '-page-' . $options['pageable_type'] . '-' . $options['pageable_id'];
        }

        if (isset($options['type']) && $options['type'] !== null && $options['type'] !== '') {
            $cacheKey .= '-type-' . $options['type'];
        }

        if (isset($options['with_image']) && $options['with_image'] === true) {
            $cacheKey .= '-image';
        }

        if (isset($options['with_parent']) && $options['with_parent'] === true) {
            $cacheKey .= '-parent';
        }

        if (isset($options['with_date']) && $options['with_date'] === true) {
            $cacheKey .= '-published';
        }

        if (isset($options['with_child_count']) && $options['with_child_count'] === true) {
            $cacheKey .= '-child-count';
        }

        if (isset($options['with_children']) && $options['with_children'] === true) {
            $cacheKey .= '-children';
        }

        if (isset($options['only_listable_types']) && ($options['only_listable_types'] === '1' || $options['only_listable_types'] === true)) {
            $cacheKey .= '-listable';
        }

        if (isset($options['page_type']) && filled($options['page_type'])) {
            $cacheKey .= '-page-type-' . $options['page_type'];
        }

        if (isset($options['page_group']) && filled($options['page_group'])) {
            $cacheKey .= '-page-group-' . $options['page_group'];
        }

        if (isset($options['type_key']) && filled($options['type_key'])) {
            $cacheKey .= '-page-type-key-' . $options['type_key'];
        }

        if (isset($options['ordering']) && filled($options['ordering'])) {
            $cacheKey .= '-ordering-' . $options['ordering'];
        }

        if ((isset($options['with_pagination']) && $options['with_pagination'] === true) && (isset($options['pagination_page']) && filled($options['pagination_page']))) {
            $cacheKey .= sprintf('-%s-%s', $options['pagination_key'] ?? 'page', $options['pagination_page']);
        }

        if (isset($options['cache_key_prepend']) && filled($options['cache_key_prepend'])) {
            $cacheKey .= '-' . $options['cache_key_prepend'];
        }

        return $cacheKey;
    }

    /**
     * Generate a cache key for page URL by ID.
     */
    public static function urlById(string $pageType, int|string $pageId, int $siteId, int $languageId): string
    {
        return sprintf('page-url-%s-%s-site-%d-lang-%d', $pageType, $pageId, $siteId, $languageId);
    }

    /**
     * Generate a cache key for previous page.
     *
     * @param  int  $pageId  The page ID
     * @param  int  $siteId  The site ID
     * @param  int  $languageId  The language ID
     * @return string The cache key
     */
    public static function pagePrevious(string $pageType, int $pageId, int $siteId, int $languageId): string
    {
        return sprintf('page-previous-%s-%d-site-%d-lang-%d', $pageType, $pageId, $siteId, $languageId);
    }

    public static function loadPage(string $type, int $pageId, int $siteId, int $languageId, string $prefix = 'page-relations'): string
    {
        return sprintf('%s-%s-page-%d-site-%d-lang-%d', $prefix, $type, $pageId, $siteId, $languageId);
    }

    public static function siteNavigations(int $siteId): string
    {
        return 'site-navigations-' . $siteId;
    }

    /**
     * Generate a cache key for navigation.
     *
     * @param  string  $key  The handle
     * @param  int  $siteId  The site ID
     * @param  int|null  $languageId  The language ID (optional)
     * @return string The cache key
     */
    public static function navigation(string $key, int $siteId, ?int $languageId = null): string
    {
        $key = sprintf('navigation-%s-site-%d', $key, $siteId);

        if ($languageId !== null) {
            $key .= '-language-' . $languageId;
        }

        return $key;
    }

    /**
     * Generate a cache key for navigation by ID.
     *
     * @param  int  $id  The navigation ID
     * @return string The cache key
     */
    public static function navigationById(int $id): string
    {
        return 'navigation-' . $id;
    }

    /**
     * Generate a cache key for media.
     *
     * @param  int  $id  The media ID
     * @return string The cache key
     */
    public static function media(int $id): string
    {
        return 'media-' . $id;
    }

    /**
     * Generate a versioned listing IDs cache key.
     * Pagination page is intentionally excluded — slicing happens in PHP.
     */
    public static function pageIds(string $specKey, int $generation): string
    {
        return $specKey . '-gen-' . $generation;
    }

    /**
     * Generate a canonical per-model cache key.
     * The short class name (via class_basename) is used so that the key stays readable.
     * Callers must pass a fully-qualified class name to avoid collisions between packages
     * that share a short model name (e.g. Capell\Core\Models\Page vs Capell\Blog\Models\Page).
     *
     * @param  class-string  $type
     */
    public static function pageModel(string $type, int $id, int $siteId, int $languageId): string
    {
        return sprintf('page-model-%s-%d-site-%d-lang-%d', class_basename($type), $id, $siteId, $languageId);
    }

    /**
     * Generate the key under which the listing generation counter is stored.
     */
    public static function listingGeneration(int $siteId, int $languageId): string
    {
        return sprintf('listing-gen-%d-%d', $siteId, $languageId);
    }
}
