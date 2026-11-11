<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\BlueprintSchemaSnapshot;
use Capell\Core\Models\PropertySet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static BlueprintSchemaSnapshot run(Blueprint $blueprint, string $reason, array<string, mixed> $metadata = [])
 */
final class CaptureBlueprintSchemaSnapshotAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(Blueprint $blueprint, string $reason, array $metadata = []): BlueprintSchemaSnapshot
    {
        $propertyDefinitions = $this->propertyDefinitions($blueprint);

        if ($propertyDefinitions !== []) {
            $metadata['property_definitions'] = $propertyDefinitions;
        }

        return BlueprintSchemaSnapshot::query()->create([
            'blueprint_id' => $blueprint->getKey(),
            'blueprint_key' => (string) $blueprint->getOriginal('key'),
            'blueprint_type' => $this->rawAttribute($blueprint, 'type') ?? (string) $blueprint->getOriginal('type'),
            'taken_at' => CarbonImmutable::now(),
            'reason' => $reason,
            'admin_before' => $this->rawAttribute($blueprint, 'admin'),
            'meta_before' => $this->rawAttribute($blueprint, 'meta'),
            'type_before' => $this->rawAttribute($blueprint, 'type'),
            'actor_id' => $this->resolveActorId(),
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }

    /**
     * The property contract the blueprint carried at capture time — each
     * attached definition's type, requirement and validation rules — so a
     * snapshot records which values the blueprint accepted, not only its form.
     *
     * @return list<array{property_set: string, key: string, type: string, requirement: string, constraints: array<string, mixed>|null}>
     */
    private function propertyDefinitions(Blueprint $blueprint): array
    {
        if ($blueprint->getKey() === null) {
            return [];
        }

        $definitions = [];
        $attachments = BlueprintPropertySet::query()
            ->where('blueprint_id', $blueprint->getKey())
            ->with('propertySet.definitions')
            ->orderBy('id')
            ->get();

        foreach ($attachments as $attachment) {
            $propertySet = $attachment->propertySet;

            if (! $propertySet instanceof PropertySet) {
                continue;
            }

            foreach ($propertySet->definitions->sortBy([['position', 'asc'], ['id', 'asc']]) as $definition) {
                $definitions[] = [
                    'property_set' => $propertySet->key,
                    'key' => $definition->key,
                    'type' => $definition->type->value,
                    'requirement' => $definition->requirement->value,
                    'constraints' => $definition->constraints?->toStored(),
                ];
            }
        }

        return $definitions;
    }

    private function rawAttribute(Blueprint $blueprint, string $key): ?string
    {
        $value = $blueprint->getRawOriginal($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveActorId(): ?int
    {
        $id = Auth::id();

        return is_int($id) ? $id : null;
    }
}
