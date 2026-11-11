<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Actions\Translations\ResolveTranslationAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * The translation in exactly this language, or null when there is none. It never
     * falls back to another language; use ResolveTranslationAction for the configured
     * fallback chain. Loaded `translation`/`translations` relations are reused, so a
     * hydrated model answers without a query.
     */
    public function translationFor(Language|int $language): ?Translation
    {
        return ResolveTranslationAction::run($this, $language, followFallback: false)?->translation;
    }

    /**
     * One translation row with no language constraint of its own. Every read must
     * constrain it to the language in context (eager load it with a `language_id`
     * constraint, or use withWhereHasLanguage()); unconstrained, it returns whichever
     * row the database yields first, so a page with en and fr rows can show either.
     * Code that only has a model and a language should call translationFor() instead.
     *
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

    /** @return Attribute<string|null, never> */
    protected function title(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->translation?->title);
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
