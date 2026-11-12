<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\TranslationResolutionData;
use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Picks the translation of a model for a requested language. The exact language
 * wins; otherwise the language's configured fallback chain is walked in order.
 * Returns null when neither exists, which public callers answer with a 404.
 * Row order never matters, unlike reading the unconstrained `translation`
 * relation. A loaded `translations` relation is reused, so render paths that
 * hydrate it ahead of time make no query here beyond the fallback chain.
 *
 * @method static TranslationResolutionData|null run(Pageable<Model>|Translatable $model, Language|int $language, bool $followFallback = true)
 */
final class ResolveTranslationAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Pageable<Model>|Translatable  $model
     */
    public function handle(Pageable|Translatable $model, Language|int $language, bool $followFallback = true): ?TranslationResolutionData
    {
        $requestedId = $language instanceof Language ? (int) $language->getKey() : $language;

        $exact = $this->exact($model, $requestedId);

        if ($exact instanceof Translation) {
            return new TranslationResolutionData($exact, $requestedId, $requestedId);
        }

        if (! $followFallback) {
            return null;
        }

        $fallbackIds = array_slice(ResolveLanguageFallbackChainAction::run($language), 1);

        if ($fallbackIds === []) {
            return null;
        }

        $candidates = $this->candidates($model, $fallbackIds);

        foreach ($fallbackIds as $fallbackId) {
            $translation = $candidates->first(
                static fn (Translation $candidate): bool => (int) $candidate->language_id === $fallbackId,
            );

            if ($translation instanceof Translation) {
                return new TranslationResolutionData($translation, $requestedId, $fallbackId);
            }
        }

        return null;
    }

    /**
     * The translation in exactly this language. A `translation` relation loaded
     * with a different language constraint is ignored rather than trusted.
     *
     * @param  Pageable<Model>|Translatable  $model
     */
    private function exact(Pageable|Translatable $model, int $languageId): ?Translation
    {
        if ($model->relationLoaded('translation')) {
            $loaded = $model->getRelation('translation');

            if ($loaded instanceof Translation && (int) $loaded->language_id === $languageId) {
                return $loaded;
            }
        }

        if ($model->relationLoaded('translations')) {
            /** @var Collection<int, Translation> $translations */
            $translations = $model->getRelation('translations');

            return $translations->first(
                static fn (Translation $candidate): bool => (int) $candidate->language_id === $languageId,
            );
        }

        $translation = $model->translations()->where('language_id', $languageId)->first();

        return $translation instanceof Translation ? $translation : null;
    }

    /**
     * @param  Pageable<Model>|Translatable  $model
     * @param  list<int>  $languageIds
     * @return Collection<int, Translation>
     */
    private function candidates(Pageable|Translatable $model, array $languageIds): Collection
    {
        if ($model->relationLoaded('translations')) {
            /** @var Collection<int, Translation> $loaded */
            $loaded = $model->getRelation('translations');

            return $loaded;
        }

        /** @var Collection<int, Translation> $translations */
        $translations = $model->translations()->whereIn('language_id', $languageIds)->get();

        return $translations;
    }
}
