<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;

/**
 * Unified cache key enum for all Capell cache entries.
 */
enum CacheEnum: string
{
    // Core cached aggregates
    case LanguageLocales = 'language_locales';

    case TotalSites = 'total_sites';

    case ExtensionPackages = 'extension_packages';

    case ExtensionInstalledNames = 'extension_installed_names';

    case ExtensionUninstalledNames = 'extension_uninstalled_names';

    // Resource / predicate keys (renamed to PascalCase; string values preserved)
    case Type = 'type';

    case HasSiteType = 'has-site-type';

    case MissingDefaultTypes = 'missing-default-types';

    case Site = 'site';

    case AllSites = 'all-sites';

    case HasDefaultSite = 'has-default-site';

    case SiteLanguages = 'site-languages';

    case LanguageByIdOrSite = 'language-by-id-or-site';

    case HasDefaultLanguage = 'has-default-language';

    case HasFoundationTheme = 'has-foundation-theme';

    case ModelDefaultExists = 'model-default-exists';

    case RelationExists = 'relation-exists';

    case FirstPageByTypeForSite = 'first-page-by-type-for-site';

    case ModelDefault = 'model-default';

    /**
     * Sitemap pages key for site/language.
     */
    public static function sitemapPages(int $siteId, int $languageId): string
    {
        return sprintf('sitemap.pages.%d.%d', $siteId, $languageId);
    }

    /**
     * Cache key for a type query with optional filter hash.
     */
    public static function typeKey(int|string|null $typeId, string $filterKey): string
    {
        return self::Type->value . '-' . ($typeId ?? 'all') . '-' . $filterKey;
    }

    /**
     * Cache key for single site resolution.
     */
    public static function siteKey(int|string|null $siteId, bool $fallbackToDefault): string
    {
        return self::Site->value . '-' . ($siteId ?? 'default') . '-' . ($fallbackToDefault ? 'fallback' : 'nofallback');
    }

    /**
     * Cache key for all sites collection, optionally modified by a closure object.
     */
    public static function allSitesKey(?object $modifyQueryUsing): string
    {
        return self::AllSites->value . '-' . ($modifyQueryUsing ? spl_object_hash($modifyQueryUsing) : 'default');
    }

    /**
     * @param  Pageable<Model>|Translation|null  $record
     */
    public static function siteLanguagesKey(int|string|null $siteId, Pageable|Translation|null $record): string
    {
        return self::SiteLanguages->value . '-' . ($siteId ?? 'all') . '-' . ($record?->getKey() ?? 'none');
    }

    /**
     * Cache key for language resolution by id or site.
     */
    public static function languageByIdOrSiteKey(?int $languageId, int $siteId): string
    {
        return self::LanguageByIdOrSite->value . '-' . ($languageId ?? 'null') . '-' . $siteId;
    }

    /**
     * Cache key indicating whether a model default exists.
     */
    public static function modelDefaultExistsKey(string $model): string
    {
        return self::ModelDefaultExists->value . '-' . $model;
    }

    /**
     * Cache key indicating existence of a relation on a model.
     */
    public static function relationExistsKey(Model $model, string $relation): string
    {
        return self::RelationExists->value . '-' . $model::class . '-' . $model->getKey() . '-' . $relation;
    }

    /**
     * Cache key for model default instance by class name.
     */
    public static function modelDefaultKey(string $modelClass): string
    {
        return self::ModelDefault->value . '-' . $modelClass;
    }
}
