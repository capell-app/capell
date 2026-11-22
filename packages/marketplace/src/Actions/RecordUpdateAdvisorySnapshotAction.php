<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\UpdateAdvisorySnapshot;
use Capell\Marketplace\Support\MarketplaceTrustedUrlPolicy;
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
            'updates' => $this->notices($payload['updates'] ?? null),
            'advisories' => $this->notices($payload['advisories'] ?? null),
            'metadata' => Arr::except($payload, [
                'checked_at',
                'capell_version',
                'updates',
                'advisories',
            ]),
        ]);
    }

    /**
     * The notices come from the remote heartbeat and the upgrade page links to them, so
     * every URL field is reduced to a trusted Marketplace https URL before it is stored.
     * An untrusted value becomes null and the page renders no link for it.
     *
     * @return array<int|string, mixed>
     */
    private function notices(mixed $notices): array
    {
        return is_array($notices) ? $this->withTrustedUrls($notices) : [];
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private function withTrustedUrls(array $value): array
    {
        $policy = resolve(MarketplaceTrustedUrlPolicy::class);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->withTrustedUrls($item);

                continue;
            }

            if (is_string($key) && preg_match('/(^|_)url$/i', $key) === 1) {
                $value[$key] = $policy->trusted(is_string($item) ? $item : null);
            }
        }

        return $value;
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
