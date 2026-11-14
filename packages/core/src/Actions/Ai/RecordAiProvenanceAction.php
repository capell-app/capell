<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Ai;

use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Models\AiProvenance;
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
        return AiProvenance::query()->updateOrCreate(
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
    }
}
