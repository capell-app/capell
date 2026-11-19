<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Closure;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Spatie\LaravelSettings\SettingsRepositories\DatabaseSettingsRepository;

/**
 * @internal The database settings row outlives the replaceable catalogue file.
 *
 * @phpstan-type LiveCatalogueWatermark array{sequence: int, fingerprint: string|null}
 */
final class CommercialCatalogueLiveState
{
    private const array KEY = ['group' => 'capell_marketplace_runtime', 'name' => 'commercial_catalogue'];

    /** @return LiveCatalogueWatermark|null */
    public function current(): ?array
    {
        return $this->decode($this->query()->value('payload'));
    }

    /** @param LiveCatalogueWatermark $watermark */
    public function adopt(array $watermark): void
    {
        // A legacy file can initialise the floor, but a concurrent delivery may already own it.
        $this->insert($this->query(), $watermark);
    }

    /** @param LiveCatalogueWatermark $watermark */
    public function record(array $watermark): bool
    {
        $query = $this->query();

        return $query->getConnection()->transaction(function () use ($query, $watermark): bool {
            $this->insert($query, $watermark);
            $held = $this->decode($query->lockForUpdate()->value('payload'));
            if ($held === $watermark) {
                return true;
            }

            if ($held === null || $held['sequence'] > $watermark['sequence']
                || ($held['sequence'] === $watermark['sequence'] && $held['fingerprint'] !== null)) {
                return false;
            }

            return $query->update(['payload' => json_encode($watermark, JSON_THROW_ON_ERROR)]) === 1;
        }, attempts: 3);
    }

    /**
     * @param  LiveCatalogueWatermark  $watermark
     * @param  Closure(): bool  $replace
     */
    public function publish(array $watermark, Closure $replace): bool
    {
        $query = $this->query();

        return $query->getConnection()->transaction(function () use ($query, $watermark, $replace): bool {
            // Take a write lock even on SQLite, where SELECT FOR UPDATE has no effect.
            $query->update(['updated_at' => now()]);

            return $this->decode($query->value('payload')) === $watermark && $replace();
        });
    }

    /** @param LiveCatalogueWatermark $watermark */
    private function insert(Builder $query, array $watermark): void
    {
        $query->insertOrIgnore([...self::KEY, 'locked' => true, 'payload' => json_encode($watermark, JSON_THROW_ON_ERROR)]);
    }

    private function query(): Builder
    {
        throw_unless(app()->bound('db'), RuntimeException::class, 'The database is unavailable during commercial catalogue discovery.');

        $repository = new DatabaseSettingsRepository(config()->array('settings.repositories.database', []));

        // A replica can lag behind acceptance and incorrectly report that the row is absent.
        return $repository->getBuilder()->toBase()->where(self::KEY)->useWritePdo();
    }

    /** @return LiveCatalogueWatermark|null */
    private function decode(mixed $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $state = is_string($payload) ? json_decode($payload, true) : null;
        throw_if(! is_array($state) || ! is_int($state['sequence'] ?? null) || $state['sequence'] < 0
            || ! array_key_exists('fingerprint', $state)
            || ($state['fingerprint'] !== null && ! is_string($state['fingerprint'])), RuntimeException::class, 'The stored commercial catalogue watermark is invalid.');

        return ['sequence' => $state['sequence'], 'fingerprint' => $state['fingerprint']];
    }
}
