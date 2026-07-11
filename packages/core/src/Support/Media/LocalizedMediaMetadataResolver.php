<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Builder;

final class LocalizedMediaMetadataResolver
{
    public function for(Media $media, int|string|Language|null $language = null): LocalizedMediaMetadata
    {
        $translation = $this->translationFor($media, $language);

        return LocalizedMediaMetadata::fromTranslation(
            languageId: $translation?->language_id,
            title: $translation?->title,
            meta: is_array($translation?->meta) ? $translation->meta : null,
        );
    }

    private function translationFor(Media $media, int|string|Language|null $language): ?Translation
    {
        if ($language instanceof Language) {
            return $this->translationByLanguageId($media, (int) $language->getKey());
        }

        if (is_int($language)) {
            return $this->translationByLanguageId($media, $language);
        }

        if (is_string($language) && $language !== '') {
            return $this->translationByLanguageCodeOrLocale($media, $language);
        }

        return $this->defaultTranslation($media);
    }

    private function translationByLanguageId(Media $media, int $languageId): ?Translation
    {
        if ($media->relationLoaded('translations')) {
            return $media->translations->firstWhere('language_id', $languageId);
        }

        return $media->translations()
            ->where('language_id', $languageId)
            ->first();
    }

    private function translationByLanguageCodeOrLocale(Media $media, string $language): ?Translation
    {
        if ($media->relationLoaded('translations')) {
            $media->translations->loadMissing('language');

            return $media->translations->first(function (Translation $translation) use ($language): bool {
                $translationLanguage = $translation->getRelationValue('language');

                return $translationLanguage instanceof Language
                    && ($translationLanguage->code === $language || $translationLanguage->locale === $language);
            });
        }

        return $media->translations()
            ->whereHas(
                'language',
                fn (Builder $query): Builder => $query
                    ->where('code', $language)
                    ->orWhere('locale', $language),
            )
            ->first();
    }

    private function defaultTranslation(Media $media): ?Translation
    {
        if ($media->relationLoaded('translations')) {
            $media->translations->loadMissing('language');

            return $media->translations->first(function (Translation $translation): bool {
                $translationLanguage = $translation->getRelationValue('language');

                return $translationLanguage instanceof Language && $translationLanguage->default === true;
            })
                ?? $media->translations->sortBy(function (Translation $translation): int {
                    $translationLanguage = $translation->getRelationValue('language');

                    return $translationLanguage instanceof Language ? $translationLanguage->order : PHP_INT_MAX;
                })->first();
        }

        return $media->translations()
            ->whereHas('language', fn (Builder $query): Builder => $query->default())
            ->first()
            ?? $media->translations()
                ->join('languages', 'translations.language_id', '=', 'languages.id')
                ->orderBy('languages.order')
                ->select('translations.*')
                ->first();
    }
}
