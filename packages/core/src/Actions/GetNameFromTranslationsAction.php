<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @phpstan-type TranslationArray array{language_id?: int|string|null, title?: string|null}
 *
 * @template T of TranslationArray|Translation
 *
 * @method static ?string run(Collection<int|string, T> $translations, Site $site)
 */
class GetNameFromTranslationsAction
{
    use AsObject;

    /**
     * @param  Collection<int|string, T>  $translations
     */
    public function handle(Collection $translations, Site $site): ?string
    {
        $translation = $translations->firstWhere('language_id', $site->language_id) ?? $translations->first();

        return $this->getTitle($translation);
    }

    /**
     * @param  TranslationArray|Translation|null  $translation
     */
    private function getTitle(array|Translation|null $translation): ?string
    {
        return is_array($translation)
            ? ($translation['title'] ?? null)
            : ($translation->title ?? null);
    }
}
