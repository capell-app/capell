<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Models\Contracts\Translatable;
use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The default-language sibling of a translation, or null when the translation is itself the default-language row or
 * its record has no default-language translation.
 *
 * @method static Translation|null run(Translation $translation)
 */
final class ResolveDefaultTranslationAction
{
    use AsFake;
    use AsObject;

    public function handle(Translation $translation): ?Translation
    {
        $translatable = $translation->translatable;

        if (! $translatable instanceof Translatable) {
            return null;
        }

        $default = $translatable->translations()
            ->whereRelation('language', 'default', true)
            ->first();

        if (! $default instanceof Translation || $default->is($translation)) {
            return null;
        }

        return $default;
    }
}
