<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Illuminate\Support\Facades\Cache;

final class CdnPurgeBuffer
{
    public const int BATCH_SIZE = 30;

    private const string PENDING_KEY = 'capell-frontend:cdn-purge:pending';

    /** @param list<string> $surrogateKeys */
    public function record(array $surrogateKeys): void
    {
        Cache::lock(self::PENDING_KEY . ':lock', 10)->block(3, function () use ($surrogateKeys): void {
            $pending = Cache::get(self::PENDING_KEY, []);
            $pending = is_array($pending) ? $pending : [];

            foreach (SurrogateKeyNormalizer::normalize($surrogateKeys) as $surrogateKey) {
                $pending[$surrogateKey] = max(0, (int) ($pending[$surrogateKey] ?? 0)) + 1;
            }

            Cache::put(self::PENDING_KEY, $pending, 3600);
        });
    }

    /** @return array<string, int> */
    public function snapshot(int $limit = self::BATCH_SIZE): array
    {
        return Cache::lock(self::PENDING_KEY . ':lock', 10)->block(3, function () use ($limit): array {
            $pending = Cache::get(self::PENDING_KEY, []);

            return is_array($pending)
                ? collect($pending)
                    ->filter(fn (mixed $count, mixed $key): bool => is_string($key) && is_numeric($count) && (int) $count > 0)
                    ->map(fn (mixed $count): int => (int) $count)
                    ->take(max(1, $limit))
                    ->all()
                : [];
        });
    }

    public function hasPending(): bool
    {
        return $this->snapshot(1) !== [];
    }

    /** @param array<string, int> $batch */
    public function acknowledge(array $batch): void
    {
        Cache::lock(self::PENDING_KEY . ':lock', 10)->block(3, function () use ($batch): void {
            $pending = Cache::get(self::PENDING_KEY, []);
            $pending = is_array($pending) ? $pending : [];

            foreach ($batch as $surrogateKey => $count) {
                $remaining = max(0, (int) ($pending[$surrogateKey] ?? 0) - $count);

                if ($remaining === 0) {
                    unset($pending[$surrogateKey]);
                } else {
                    $pending[$surrogateKey] = $remaining;
                }
            }

            if ($pending === []) {
                Cache::forget(self::PENDING_KEY);

                return;
            }

            Cache::put(self::PENDING_KEY, $pending, 3600);
        });
    }
}
