<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintSchemaSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static BlueprintSchemaSnapshot run(Blueprint $blueprint, string $reason, array<string, mixed> $metadata = [])
 */
final class CaptureBlueprintSchemaSnapshotAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(Blueprint $blueprint, string $reason, array $metadata = []): BlueprintSchemaSnapshot
    {
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
