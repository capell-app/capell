<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\MarketplaceCommercialWarningData;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildMarketplaceCommercialWarningsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>|null  $commercial
     * @return list<MarketplaceCommercialWarningData>
     */
    public function handle(?array $commercial = null): array
    {
        $commercial ??= $this->storedCommercialState();
        $purchases = is_array($commercial['purchases'] ?? null) ? $commercial['purchases'] : [];

        $warnings = collect($purchases)
            ->filter(fn (mixed $purchase): bool => is_array($purchase))
            ->map(fn (array $purchase): ?MarketplaceCommercialWarningData => $this->warning($purchase))
            ->filter(fn (?MarketplaceCommercialWarningData $warning): bool => $warning instanceof MarketplaceCommercialWarningData)
            ->values()
            ->all();

        return array_values($warnings);
    }

    /** @return array<string, mixed> */
    private function storedCommercialState(): array
    {
        $commercial = resolve(MarketplaceInstanceResolver::class)->latest()?->connection_metadata['commercial'] ?? [];

        return is_array($commercial) ? $commercial : [];
    }

    /** @param array<string, mixed> $purchase */
    private function warning(array $purchase): ?MarketplaceCommercialWarningData
    {
        $name = $this->nonEmptyString($purchase['name'] ?? null);

        if ($name === null) {
            return null;
        }

        $status = $this->nonEmptyString($purchase['status'] ?? null) ?? 'unknown';
        $accessEndsAt = $this->date($purchase['access_ends_at'] ?? null);
        $updatesExpired = ($purchase['protected_updates'] ?? true) === false
            || in_array($status, ['expired', 'updates_expired'], true);
        $expiresSoon = $accessEndsAt instanceof CarbonImmutable
            && $accessEndsAt->isFuture()
            && $accessEndsAt->lessThanOrEqualTo(now()->addDays(30));
        $expired = $accessEndsAt instanceof CarbonImmutable && $accessEndsAt->isPast();

        if (! $updatesExpired && ! $expiresSoon && ! $expired) {
            return null;
        }

        $keySource = $this->nonEmptyString($purchase['id'] ?? $purchase['product_id'] ?? null) ?? $name;

        return new MarketplaceCommercialWarningData(
            key: hash('xxh3', $keySource),
            name: $name,
            status: $updatesExpired || $expired ? 'updates_expired' : 'expiring_soon',
            severity: $updatesExpired || $expired ? 'danger' : 'warning',
            accessEndsAt: $accessEndsAt,
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
