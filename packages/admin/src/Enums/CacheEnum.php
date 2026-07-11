<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Models\Site;

enum CacheEnum: string
{
    // Basic keys
    case SiteDefault = 'site_default';

    case SiteAll = 'site_all';

    case SiteTotal = 'site_total';

    case LanguageAll = 'language_all';

    case LanguageTotal = 'language_total';

    /**
     * Generate a cache key for a specific site.
     *
     * @param  int|string  $siteId  The site ID
     * @return string The cache key
     */
    public static function site(int|string $siteId): string
    {
        return 'site.' . $siteId;
    }

    /**
     * Generate a cache key for languages of a specific site.
     *
     * @param  int|string  $siteId  The site ID
     * @return string The cache key
     */
    public static function siteLanguages(int|string $siteId): string
    {
        return Site::class . '::' . $siteId . '::languages';
    }

    /**
     * Generate a cache key for site tabs of a specific model and relation.
     *
     * @param  string  $model  The model class
     * @param  string  $relation  The relation name
     * @return string The cache key
     */
    public static function siteTabs(string $model, string $relation): string
    {
        return 'site_tabs_' . $model . '_' . $relation;
    }

    public static function language(int $languageId): string
    {
        return 'language.' . $languageId;
    }
}
