<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Contributors;

use Capell\Core\EventSourcing\Contracts\PageStateContributor;
use Capell\Core\Models\AiProvenance;
use Capell\Core\Models\Page;
use Override;

/**
 * Keeps AI provenance with the page revision, so rolling a page back restores
 * which fields were AI-written and whether a person had reviewed them then.
 */
final class AiProvenancePageStateContributor implements PageStateContributor
{
    private const array COLUMNS = [
        'language_id',
        'field',
        'generation_history_id',
        'provider',
        'model',
        'capability',
        'actor_id',
        'confirmed_by',
        'confirmed_at',
        'disclosure',
    ];

    #[Override]
    public function key(): string
    {
        return 'aiProvenance';
    }

    #[Override]
    public function capture(Page $page): array
    {
        return AiProvenance::query()
            ->where('pageable_type', $page->getMorphClass())
            ->where('pageable_id', $page->getKey())
            ->orderBy('language_id')
            ->orderBy('field')
            ->get()
            ->map(static fn (AiProvenance $row): array => [
                'language_id' => $row->language_id,
                'field' => $row->field,
                'generation_history_id' => $row->generation_history_id,
                'provider' => $row->provider,
                'model' => $row->model,
                'capability' => $row->capability,
                'actor_id' => $row->actor_id,
                'confirmed_by' => $row->confirmed_by,
                'confirmed_at' => $row->confirmed_at?->toIso8601String(),
                'disclosure' => $row->disclosure->value,
            ])
            ->values()
            ->all();
    }

    #[Override]
    public function restore(Page $page, array $state): void
    {
        AiProvenance::query()
            ->where('pageable_type', $page->getMorphClass())
            ->where('pageable_id', $page->getKey())
            ->delete();

        foreach ($state as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! is_string($row['field'] ?? null)) {
                continue;
            }

            $attributes = array_intersect_key($row, array_flip(self::COLUMNS));

            AiProvenance::query()->create([
                ...$attributes,
                'pageable_type' => $page->getMorphClass(),
                'pageable_id' => $page->getKey(),
            ]);
        }
    }
}
