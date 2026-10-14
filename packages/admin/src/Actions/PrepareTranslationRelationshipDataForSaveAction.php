<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrepareTranslationRelationshipDataForSaveAction
{
    use AsObject;

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    public function handle(Translation $record, array $data): ?array
    {
        $candidate = clone $record;
        $candidate->fill($data);

        foreach (['meta', 'content'] as $attribute) {
            $original = $record->getAttribute($attribute);
            $submitted = $candidate->getAttribute($attribute);
            if (! is_array($original)) {
                continue;
            }

            if (! is_array($submitted)) {
                continue;
            }

            if (! $this->equivalentJsonArrays($original, $submitted)) {
                continue;
            }

            // Preserve database JSON formatting and object key order, even when
            // another field changes. Ordered lists and scalar types stay strict.
            unset($data[$attribute]);
            $candidate->setRawAttributes(array_replace($candidate->getAttributes(), [
                $attribute => $record->getAttributes()[$attribute],
            ]));
        }

        return $candidate->isDirty() ? $data : null;
    }

    /**
     * @param  array<array-key, mixed>  $original
     * @param  array<array-key, mixed>  $submitted
     */
    private function equivalentJsonArrays(array $original, array $submitted): bool
    {
        if (count($original) !== count($submitted) || array_is_list($original) !== array_is_list($submitted)) {
            return false;
        }

        foreach ($original as $key => $value) {
            if (! array_key_exists($key, $submitted)) {
                return false;
            }

            $submittedValue = $submitted[$key];
            if (is_array($value) && is_array($submittedValue)) {
                if (! $this->equivalentJsonArrays($value, $submittedValue)) {
                    return false;
                }
            } elseif ($value !== $submittedValue) {
                return false;
            }
        }

        return true;
    }
}
