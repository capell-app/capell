<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\UpdateAdvisorySnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class RecordUpdateAdvisorySnapshotAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $source, array $payload): UpdateAdvisorySnapshot
    {
        return UpdateAdvisorySnapshot::query()->create([
            'source' => $source,
            'checked_at' => $this->checkedAtFromPayload($payload['checked_at'] ?? null),
            'capell_version' => $payload['capell_version'] ?? null,
            'updates' => is_array($payload['updates'] ?? null) ? $payload['updates'] : [],
            'advisories' => is_array($payload['advisories'] ?? null) ? $payload['advisories'] : [],
            'metadata' => Arr::except($payload, [
                'checked_at',
                'capell_version',
                'updates',
                'advisories',
            ]),
        ]);
    }

    private function checkedAtFromPayload(mixed $checkedAt): DateTimeInterface
    {
        if ($checkedAt instanceof DateTimeInterface) {
            return $checkedAt;
        }

        if (! is_string($checkedAt) || trim($checkedAt) === '') {
            return now();
        }

        try {
            return CarbonImmutable::parse($checkedAt);
        } catch (Throwable) {
            return now();
        }
    }
}
