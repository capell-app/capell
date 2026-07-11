<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Layout;
use Capell\Core\Models\LayoutContentSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static LayoutContentSnapshot run(Layout $layout, string $reason, array<string, mixed> $metadata = [])
 */
final class CaptureLayoutContentSnapshotAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(Layout $layout, string $reason, array $metadata = []): LayoutContentSnapshot
    {
        return LayoutContentSnapshot::query()->create([
            'layout_id' => $layout->getKey(),
            'site_id' => $layout->site_id,
            'theme_id' => $layout->theme_id,
            'taken_at' => CarbonImmutable::now(),
            'reason' => $reason,
            'containers_before' => $this->rawAttribute($layout, 'containers'),
            'admin_before' => $this->rawAttribute($layout, 'admin'),
            'meta_before' => $this->rawAttribute($layout, 'meta'),
            'elements_before' => $this->rawAttribute($layout, 'elements'),
            'actor_id' => $this->resolveActorId(),
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }

    private function rawAttribute(Layout $layout, string $key): ?string
    {
        $value = $layout->getRawOriginal($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveActorId(): ?int
    {
        $id = Auth::id();

        return is_int($id) ? $id : null;
    }
}
