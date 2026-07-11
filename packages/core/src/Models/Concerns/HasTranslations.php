<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * @mixin Pageable<Model>|Site
 */
trait HasTranslations
{
    public function getTranslation(string $key): mixed
    {
        return $this->translation[$key] ?? null;
    }

    public function getPrimaryLanguage(): ?Language
    {
        return $this->languages()->ordered()->first();
    }

    /**
     * @return HasManyThrough<Language, Translation, $this>
     */
    public function languages(): HasManyThrough
    {
        return $this->hasManyThrough(
            Language::class,
            Translation::class,
            'translatable_id',
            'id',
            'id',
            'language_id',
        )
            ->where('translatable_type', $this->getMorphClass());
    }

    /**
     * @return HasOne<Translation, $this>|MorphOne<Translation, $this>
     */
    public function translation(): HasOne|MorphOne
    {
        return $this->morphOne(Translation::class, 'translatable')
            ->chaperone('translatable');
    }

    /**
     * @return HasMany<Translation, $this>|MorphMany<Translation, $this>
     */
    public function translations(): HasMany|MorphMany
    {
        $model = $this->morphMany(Translation::class, 'translatable');
        $model->chaperone();

        return $model;
    }

    protected function getTitleAttribute(): ?string
    {
        return $this->translation?->title;
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function scopeWithWhereHasLanguage(Builder $query, int $language_id): void
    {
        $query->withWhereHas(
            'translation',
            fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language_id),
        );
    }
}
