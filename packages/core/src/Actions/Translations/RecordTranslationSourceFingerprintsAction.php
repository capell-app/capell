<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Saving a translation in another language marks it as caught up with the default language: the current fingerprints
 * of the default-language fields are stored on it, so later default-language edits flag only the fields they touch.
 * Runs while the translation is being saved and does not save it.
 *
 * @method static void run(Translation $translation)
 */
final class RecordTranslationSourceFingerprintsAction
{
    use AsFake;
    use AsObject;

    private const array TRACKED_ATTRIBUTES = ['title', 'content', 'meta'];

    public function handle(Translation $translation): void
    {
        if ($translation->exists && ! $translation->isDirty(self::TRACKED_ATTRIBUTES)) {
            return;
        }

        $default = ResolveDefaultTranslationAction::run($translation);

        if (! $default instanceof Translation) {
            return;
        }

        $translation->setAttribute('source_fingerprints', FingerprintTranslationFieldsAction::run($default));
    }
}
