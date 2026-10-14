<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Content\ContentStructureResolver;
use Capell\Core\Support\Json\JsonEquivalence;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrepareTranslationRelationshipDataForSaveAction
{
    use AsFake;
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
            // Unsubmitted attributes keep their stored value, so there is nothing
            // to compare and no reason to load the translatable's structure.
            if (! array_key_exists($attribute, $data)) {
                continue;
            }

            $original = $record->getAttributes()[$attribute] ?? null;
            $submitted = $candidate->getAttributes()[$attribute] ?? null;
            if (! is_string($original)) {
                continue;
            }

            if (! is_string($submitted)) {
                continue;
            }

            $equivalent = $attribute === 'content' && ContentStructureResolver::resolve($candidate) !== ContentStructure::Blocks
                ? $original === $submitted
                : JsonEquivalence::equivalent($original, $submitted);

            if (! $equivalent) {
                continue;
            }

            // Preserve equivalent JSON formatting or identical HTML, even when
            // another field changes. Ordered lists and scalar types stay strict.
            unset($data[$attribute]);
        }

        // Even a clean translation must run saving/saved listeners and Filament hooks.
        return $data;
    }
}
