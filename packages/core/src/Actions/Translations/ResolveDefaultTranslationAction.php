<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The default-language sibling of a translation, or null when the translation is itself the default-language row or
 * its record has no default-language translation. Queries by the translation's own morph columns, so it loads no
 * relations on the given model.
 *
 * @method static Translation|null run(Translation $translation)
 */
final class ResolveDefaultTranslationAction
{
    use AsFake;
    use AsObject;

    public function handle(Translation $translation): ?Translation
    {
        $translatableType = $translation->getAttribute('translatable_type');
        $translatableId = $translation->getAttribute('translatable_id');

        if ($translatableType === null || $translatableId === null) {
            return null;
        }

        $query = Translation::query()
            ->where('translatable_type', $translatableType)
            ->where('translatable_id', $translatableId)
            ->whereRelation('language', 'default', true);

        if ($translation->exists) {
            $query->whereKeyNot($translation->getKey());
        }

        $default = $query->first();

        if (! $default instanceof Translation || (int) $default->getAttribute('language_id') === (int) $translation->getAttribute('language_id')) {
            return null;
        }

        return $default;
    }
}
