<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Ai;

use Capell\Core\Models\AiProvenance;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records that a person reviewed AI-written fields. This is the "reviewed by a
 * human" evidence: who confirmed and when. Pass field names to confirm only
 * those; with none, every unconfirmed field of the page is confirmed.
 */
final class ConfirmAiProvenanceAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>|null  $fields
     */
    public function handle(Model $pageable, int $reviewerId, ?int $languageId = null, ?array $fields = null): int
    {
        $query = AiProvenance::query()
            ->where('pageable_type', $pageable->getMorphClass())
            ->where('pageable_id', $pageable->getKey())
            ->whereNull('confirmed_at');

        if ($languageId !== null) {
            $query->where('language_id', $languageId);
        }

        if ($fields !== null) {
            $query->whereIn('field', $fields);
        }

        return $query->update([
            'confirmed_by' => $reviewerId,
            'confirmed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
