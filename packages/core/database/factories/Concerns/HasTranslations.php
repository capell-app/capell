<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories\Concerns;

use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Concerns\HasTranslations as ModelHasTranslations;
use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @mixin Factory<ModelHasTranslations>
 */
trait HasTranslations
{
    /**
     * @param  array<int, Language>|Collection<int, Language>|Language|null  $languages
     * @param  array<int|string, mixed>  $data
     */
    public function withTranslations(
        null|array|Collection|Language $languages = null,
        array $data = [],
        ?string $slug = null,
        ContentStructure $contentStructure = ContentStructure::Html,
    ): static {
        return $this->afterCreating(function (Translatable $model) use ($languages, $data, $slug, $contentStructure): void {
            if ($languages instanceof Language) {
                $languages = collect([$languages]);
            } elseif (is_array($languages)) {
                $languages = collect($languages);
            } elseif (blank($languages)) {
                $languages ??= $model->site->languages;
            }

            if ($languages->doesntContain('id', $model->site->language->id)) {
                $languages = $languages->prepend($model->site->language);
            }

            $languages->each(function (Language $language) use ($model, $data, $slug, $contentStructure): void {
                $languageData = $data[$language->id] ?? $data;
                $title = $model->name . ' ' . $language->locale;

                $slug ??= str($title)->slug();

                $languageData['language_id'] = $language->id;
                $languageData['pageable_id'] = $model->getKey();
                $languageData['pageable_type'] = $model->getMorphClass();

                if (! isset($languageData['title'])) {
                    $languageData['title'] = $title;
                }

                $translation = Translation::factory()
                    ->content($contentStructure)
                    ->state($languageData)
                    ->meta($data)
                    ->slug($slug)
                    ->make();

                $model->translations()->create(
                    $translation->only($translation->getFillable()),
                );
            });
        });
    }
}
