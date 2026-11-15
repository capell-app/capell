<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Ai;

use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Models\AiProvenance;
use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records that an AI capability wrote a field. A new write replaces the field's
 * row and clears any earlier confirmation: the person must review what the AI
 * wrote this time, not what it wrote before.
 */
final class RecordAiProvenanceAction
{
    use AsFake;
    use AsObject;

    public function handle(AiProvenanceRecordData $record): AiProvenance
    {
        $provenance = AiProvenance::query()->updateOrCreate(
            [
                'pageable_type' => $record->pageable->getMorphClass(),
                'pageable_id' => $record->pageable->getKey(),
                'language_id' => $record->languageId,
                'field' => $record->field,
            ],
            [
                'disclosure' => $record->disclosure,
                'capability' => $record->capability,
                'provider' => $record->provider,
                'model' => $record->model,
                'generation_history_id' => $record->generationHistoryId,
                'actor_id' => $record->actorId,
                'confirmed_by' => null,
                'confirmed_at' => null,
            ],
        );

        if ($record->pageable instanceof Page) {
            $this->summariseOnPage($record->pageable);
        }

        return $provenance;
    }

    /**
     * Keep the page's strongest disclosure on its own meta, so the public site can
     * mark the page from data it already has. Saved quietly: the content change the
     * AI made records the revision.
     */
    private function summariseOnPage(Page $page): void
    {
        $strongest = AiDisclosure::None;

        $rows = AiProvenance::query()
            ->where('pageable_type', $page->getMorphClass())
            ->where('pageable_id', $page->getKey())
            ->get(['disclosure']);

        foreach ($rows as $row) {
            if ($row->disclosure->rank() > $strongest->rank()) {
                $strongest = $row->disclosure;
            }
        }

        $meta = is_array($page->meta) ? $page->meta : [];
        $meta[AiDisclosure::PAGE_META_KEY] = $strongest->value;
        $page->forceFill(['meta' => $meta])->saveQuietly();
    }
}
