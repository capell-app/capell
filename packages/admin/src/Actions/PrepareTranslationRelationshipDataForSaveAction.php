<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Translation;
use Capell\Core\Support\Json\JsonEquivalence;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrepareTranslationRelationshipDataForSaveAction
{
    use AsObject;

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function handle(Translation $record, array $data): array
    {
        $candidate = clone $record;
        $candidate->fill($data);

        foreach (['meta', 'content'] as $attribute) {
            $original = $record->getAttributes()[$attribute] ?? null;
            $submitted = $candidate->getAttributes()[$attribute] ?? null;
            if (! is_string($original)) {
                continue;
            }

            if (! is_string($submitted)) {
                continue;
            }

            if (! JsonEquivalence::equivalent($original, $submitted)) {
                continue;
            }

            // Preserve database JSON formatting and object key order, even when
            // another field changes. Ordered lists and scalar types stay strict.
            unset($data[$attribute]);
        }

        // Even a clean translation must run saving/saved listeners and Filament hooks.
        return $data;
    }
}
