<?php

declare(strict_types=1);

namespace Capell\Core\Support;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Lookup\ArrayCache;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class CapellCoreHelper
{
    /** @param array<string, mixed>|object|string|null $typeFilter */
    public static function getBlueprint(null|int|string $typeId = null, array|object|string|null $typeFilter = null): ?Blueprint
    {
        $typeId = is_string($typeId) ? (int) $typeId : $typeId;
        $filterKey = is_array($typeFilter)
            ? self::hashArray($typeFilter)
            : (is_object($typeFilter) ? spl_object_hash($typeFilter) : (string) $typeFilter);
        $cacheKey = CacheEnum::typeKey($typeId, $filterKey);

        $result = self::getCached($cacheKey, function () use ($typeId, $typeFilter): Blueprint|false {
            /** @var class-string<Blueprint> $model */
            $model = Blueprint::class;

            $query = $model::query();
            if ($typeId !== null) {
                $query->whereKey($typeId);
            }

            if ($typeFilter !== null) {
                if (is_array($typeFilter)) {
                    $query->where($typeFilter);
                } elseif (is_callable($typeFilter)) {
                    $typeFilter($query);
                }
            }

            $result = $query->first();

            return $result instanceof Blueprint ? $result : false;
        });

        return $result instanceof Blueprint ? $result : null;
    }

    /** @param array<string, mixed>|object|string|null $typeFilter */
    public static function clearType(null|int|string $typeId = null, array|object|string|null $typeFilter = null): void
    {
        $typeId = is_string($typeId) ? (int) $typeId : $typeId;
        $filterKey = is_array($typeFilter)
            ? self::hashArray($typeFilter)
            : (is_object($typeFilter) ? spl_object_hash($typeFilter) : (string) $typeFilter);
        $cacheKey = CacheEnum::typeKey($typeId, $filterKey);

        resolve(ArrayCache::class)->forget($cacheKey);
    }

    public static function hasSiteType(): bool
    {
        return self::getCached(
            CacheEnum::HasSiteType->value,
            function (): bool {
                /** @var class-string<Blueprint> $model */
                $model = Blueprint::class;

                return $model::query()->siteType()->exists();
            },
            true,
        );
    }

    public static function missingDefaultTypes(): bool
    {
        return self::getCached(
            CacheEnum::MissingDefaultTypes->value,
            function (): bool {
                /** @var class-string<Blueprint> $model */
                $model = Blueprint::class;

                return $model::missingDefaultTypes();
            },
            true,
        );
    }

    public static function getSite(null|int|string $siteId, bool $fallbackToDefault = true): ?Site
    {
        $siteId = is_string($siteId) ? (int) $siteId : $siteId;
        $cacheKey = CacheEnum::siteKey($siteId, $fallbackToDefault);

        $result = self::getCached($cacheKey, function () use ($siteId, $fallbackToDefault): Site|false {
            /** @var class-string<Site> $siteModel */
            $siteModel = Site::class;
            $site = ($siteId !== null && $siteId !== 0) ? $siteModel::query()->find($siteId) : null;
            if ($site === null && $fallbackToDefault) {
                $site = $siteModel::query()->default()->first();
            }

            return $site ?? false;
        });

        return $result instanceof Site ? $result : null;
    }

    /**
     * @param  Closure(Builder<Site>): Builder<Site>|null  $modifyQueryUsing
     * @return Collection<int, Site>
     */
    public static function getSites(?Closure $modifyQueryUsing = null): Collection
    {
        $cacheKey = CacheEnum::allSitesKey($modifyQueryUsing);

        return self::getCached(
            $cacheKey,
            function () use ($modifyQueryUsing): Collection {
                /** @var class-string<Site> $model */
                $model = Site::class;

                $query = $model::query();
                if ($modifyQueryUsing instanceof Closure) {
                    $query = $modifyQueryUsing($query);
                }

                return $query->get();
            },
        );
    }

    public static function hasDefaultSite(): bool
    {
        return self::getCached(
            CacheEnum::HasDefaultSite->value,
            fn (): bool => self::getSite(null, true)?->exists() ?? false,
            true,
        );
    }

    /**
     * @param  Pageable<Model>|Translation|null  $record
     * @return Collection<int, Language>
     */
    public static function getSiteLanguagesForRecord(null|Pageable|Translation $record, null|int|string $siteId = null): Collection
    {
        $siteId = is_string($siteId) ? (int) $siteId : $siteId;
        $cacheKey = CacheEnum::siteLanguagesKey($siteId, $record);

        $result = self::getCached($cacheKey, function () use ($record, $siteId): Collection|false {
            $languages = null;
            if ($record instanceof Model && $record->relationLoaded('site')) {
                $site = $record->getRelation('site');
            } elseif ($record instanceof Translation && $record->translatable instanceof Pageable) {
                /** @var Pageable<Model>&Model $translatable */
                $translatable = $record->translatable;
                $site = $translatable->getAttribute('site');
            } else {
                $site = null;
            }

            if ($site !== null && $site->id === $siteId) {
                $languages = $site->languages()->ordered()->get();
            } elseif ($siteId !== null && $siteId !== 0) {
                /** @var class-string<Site> $siteModel */
                $siteModel = Site::class;
                $site = $siteModel::query()->find($siteId);
                if ($site !== null) {
                    $languages = $site->languages()->ordered()->get();
                }
            }

            if (! $languages) {
                /** @var class-string<Language> $languageModel */
                $languageModel = Language::class;
                $languages = $languageModel::query()->ordered()->get();
            }

            return $languages->isEmpty() ? false : $languages;
        });

        return $result instanceof Collection ? $result : new Collection;
    }

    public static function getLanguageByIdOrSite(?int $languageId, int $siteId): ?Language
    {
        $cacheKey = CacheEnum::languageByIdOrSiteKey($languageId, $siteId);

        $result = self::getCached(
            $cacheKey,
            function () use ($languageId, $siteId): Language|false {
                /** @var class-string<Language> $languageModel */
                $languageModel = Language::class;
                if ($languageId !== null && $languageId !== 0) {
                    $found = $languageModel::query()->find($languageId);

                    return $found instanceof Language ? $found : false;
                }

                $found = $languageModel::query()->whereHas('sites', function (Builder $query) use ($siteId): void {
                    $query->whereKey($siteId);
                })->first();

                return $found instanceof Language ? $found : false;
            },
        );

        return $result instanceof Language ? $result : null;
    }

    public static function hasDefaultLanguage(): bool
    {
        return self::getCached(
            CacheEnum::HasDefaultLanguage->value,
            function (): bool {
                /** @var class-string<Language> $languageModel */
                $languageModel = Language::class;

                return $languageModel::query()->default()->exists();
            },
            true,
        );
    }

    public static function hasFoundationTheme(): bool
    {
        return self::getCached(
            CacheEnum::HasFoundationTheme->value,
            function (): bool {
                /** @var class-string<Theme> $model */
                $model = Theme::class;

                return $model::query()->default()->exists();
            },
            true,
        );
    }

    public static function modelDefaultExists(string $model): bool
    {
        $cacheKey = CacheEnum::modelDefaultExistsKey($model);

        return self::getCached(
            $cacheKey,
            fn (): bool => is_subclass_of($model, Model::class) ? $model::query()->default()->exists() : false,
            true,
        );
    }

    public static function relationExists(Model $model, string $relation): bool
    {
        if (! method_exists($model, $relation) || ! is_callable([$model, $relation])) {
            return false;
        }

        $relationObj = call_user_func([$model, $relation]);

        // Ensure we only call exists() when available on the relation object.
        return is_object($relationObj) && method_exists($relationObj, 'exists') && (bool) $relationObj->exists();
    }

    /**
     * @param  array<int, int>  $siteLanguageIds
     * @return array<int, string>
     */
    public static function getLanguageCodesByIds(array $siteLanguageIds): array
    {
        $cacheKey = 'language-codes-by-ids-' . self::hashArray($siteLanguageIds);

        return self::getCached(
            $cacheKey,
            function () use ($siteLanguageIds): array {
                /** @var class-string<Language> $model */
                $model = Language::class;

                return $model::query()
                    ->whereIn('id', $siteLanguageIds)
                    ->pluck('code')
                    ->toArray();
            },
        );
    }

    /**
     * @param  array<int, string>  $codes
     * @return Collection<int, Language>
     */
    public static function languagesByCodes(array $codes): Collection
    {
        $cacheKey = 'languages-by-codes-' . self::hashArray($codes);

        return self::getCached(
            $cacheKey,
            function () use ($codes): Collection {
                /** @var class-string<Language> $model */
                $model = Language::class;

                return $model::query()
                    ->whereIn('code', $codes)
                    ->get();
            },
        );
    }

    /** @param array<int, CacheEnum|string>|CacheEnum|string|null $prefixes */
    public static function flushCache(null|string|array|CacheEnum $prefixes = null): int
    {
        if ($prefixes instanceof CacheEnum) {
            $prefixes = $prefixes->value;
        }

        if (is_array($prefixes)) {
            $prefixes = array_map(
                static fn (CacheEnum|string $prefix): string => $prefix instanceof CacheEnum ? $prefix->value : $prefix,
                $prefixes,
            );
        }

        return resolve(ArrayCache::class)->flush($prefixes);
    }

    private static function getCached(string $key, callable $resolver, bool $asBool = false): mixed
    {
        return resolve(ArrayCache::class)->remember($key, $resolver, $asBool);
    }

    /** @param array<mixed> $value */
    private static function hashArray(array $value): string
    {
        return hash('sha256', JsonCodec::encode($value));
    }
}
