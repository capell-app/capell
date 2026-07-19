<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Loader;

use Capell\Admin\Enums\CacheEnum;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class LanguageLoader
{
    /** @return Collection<int, Language> */
    public static function all(): Collection
    {
        $model = self::getModel();

        return $model::query()->ordered()->get();
    }

    public static function getDefault(): ?Language
    {
        $model = self::getModel();

        return $model::getDefault();
    }

    /** @return Collection<int, Language> */
    public static function languages(?int $siteId): Collection
    {
        if ($siteId === null || $siteId === 0) {
            return Cache::remember(
                CacheEnum::LanguageAll->value,
                30,
                fn (): Collection => Language::query()->enabled()->ordered()->get(),
            );
        }

        return Cache::remember(
            CacheEnum::siteLanguages($siteId),
            30,
            fn (): Collection => Language::query()->whereRelation('sites', 'sites.id', $siteId)->enabled()->ordered()->get(),
        );
    }

    public static function getTotalLanguages(): int
    {
        return Cache::remember(
            CacheEnum::LanguageTotal->value,
            30,
            fn (): int => Language::query()->count(),
        );
    }

    public static function total(): int
    {
        $model = self::getModel();

        return $model::query()->enabled()->count();
    }

    public function loadById(int $languageId): Language
    {
        return Cache::remember(
            CacheEnum::language($languageId),
            30,
            fn (): Language => Language::query()->findOrFail($languageId),
        );
    }

    /**
     * @return class-string<Language>
     */
    private static function getModel(): string
    {
        return Language::class;
    }
}
