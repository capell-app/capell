<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Translations;

use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Fingerprints each translatable field of a translation: the title, every top-level content key and every meta key,
 * addressed as `title`, `content.<key>` and `meta.<key>`. Comparing two fingerprint sets shows which fields changed.
 *
 * @method static array<string, string> run(Translation $translation)
 */
final class FingerprintTranslationFieldsAction
{
    use AsFake;
    use AsObject;

    /** @return array<string, string> */
    public function handle(Translation $translation): array
    {
        $fingerprints = ['title' => $this->fingerprint($translation->getAttribute('title'))];

        foreach (['content', 'meta'] as $group) {
            $values = $this->decoded($translation->getAttribute($group));

            if (! is_array($values)) {
                if ($values !== null && $values !== '') {
                    $fingerprints[$group] = $this->fingerprint($values);
                }

                continue;
            }

            foreach ($values as $key => $value) {
                $fingerprints[$group . '.' . $key] = $this->fingerprint($value);
            }
        }

        ksort($fingerprints);

        return $fingerprints;
    }

    /** A single rich-text document stays one field; a JSON object of fields is split by key. */
    private function decoded(mixed $value): mixed
    {
        if (! is_string($value) || ! str_starts_with(ltrim($value), '{')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }

    private function fingerprint(mixed $value): string
    {
        return hash('xxh128', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
