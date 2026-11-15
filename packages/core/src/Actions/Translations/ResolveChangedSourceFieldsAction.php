<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The default-language fields that changed since a translation was last saved, addressed as `title`, `content.<key>`
 * and `meta.<key>`. Returns null when the translation has no recorded fingerprints yet (saved before tracking began),
 * so callers can fall back to comparing save times.
 *
 * @method static list<string>|null run(Translation $translation, Translation $defaultTranslation)
 */
final class ResolveChangedSourceFieldsAction
{
    use AsFake;
    use AsObject;

    /** @return list<string>|null */
    public function handle(Translation $translation, Translation $defaultTranslation): ?array
    {
        $recorded = $translation->getAttribute('source_fingerprints');

        if (! is_array($recorded)) {
            return null;
        }

        $current = FingerprintTranslationFieldsAction::run($defaultTranslation);
        $changed = [];

        foreach ($current as $field => $fingerprint) {
            if (($recorded[$field] ?? null) !== $fingerprint) {
                $changed[] = $field;
            }
        }

        foreach (array_keys($recorded) as $field) {
            if (! array_key_exists((string) $field, $current)) {
                $changed[] = (string) $field;
            }
        }

        sort($changed);

        return $changed;
    }
}
