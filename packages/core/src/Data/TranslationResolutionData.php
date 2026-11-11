<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

/**
 * The translation chosen for a requested language, and whether it came from a
 * fallback language. Render data that serves fallback content must say so, so
 * hreflang alternates and the document language can exclude or label it.
 */
final class TranslationResolutionData extends Data
{
    public function __construct(
        #[Hidden]
        public readonly Translation $translation,
        public readonly int $requestedLanguageId,
        public readonly int $servedLanguageId,
    ) {}

    /**
     * Describes the translation already hydrated on a model for a requested language,
     * without querying: public Blade and render actions call this on hydrated models.
     * A loaded translation in another language counts as fallback content only when
     * the model has no translation in the requested language at all; when that is
     * unknown (`translations` not loaded) it is still reported, so callers err towards
     * treating mismatched content as fallback rather than as a real translation.
     *
     * @param  Pageable<Model>|Translatable  $model
     */
    public static function forLoaded(Pageable|Translatable $model, Language|int $language): ?self
    {
        $requestedId = $language instanceof Language ? (int) $language->getKey() : $language;
        $translation = $model->relationLoaded('translation') ? $model->getRelation('translation') : null;

        if (! $translation instanceof Translation) {
            return null;
        }

        $servedId = (int) $translation->language_id;

        if ($servedId !== $requestedId && $model->relationLoaded('translations')) {
            /** @var Collection<int, Translation> $translations */
            $translations = $model->getRelation('translations');
            $exact = $translations->first(
                static fn (Translation $candidate): bool => (int) $candidate->language_id === $requestedId,
            );

            if ($exact instanceof Translation) {
                return new self($exact, $requestedId, $requestedId);
            }
        }

        return new self($translation, $requestedId, $servedId);
    }

    public function isFallback(): bool
    {
        return $this->servedLanguageId !== $this->requestedLanguageId;
    }
}
