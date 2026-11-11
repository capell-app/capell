<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\TranslationResolutionData;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The language a rendered document is written in. A page served from a fallback
 * language is in that language, not the requested one, so `lang` and `dir` follow
 * the served translation. Reads hydrated relations only: public Blade calls this.
 *
 * @method static Language|null run(?Pageable $page, ?Language $language)
 */
final class ResolveDocumentLanguageAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Pageable<Model>|null  $page
     */
    public function handle(?Pageable $page, ?Language $language): ?Language
    {
        if (! $page instanceof Pageable || ! $language instanceof Language) {
            return $language;
        }

        $resolution = TranslationResolutionData::forLoaded($page, $language);

        if ($resolution?->isFallback() !== true || ! $resolution->translation->relationLoaded('language')) {
            return $language;
        }

        $served = $resolution->translation->getRelation('language');

        return $served instanceof Language ? $served : $language;
    }
}
