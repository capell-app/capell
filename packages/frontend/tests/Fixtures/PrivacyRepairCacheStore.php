<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Override;
use RuntimeException;

final class PrivacyRepairCacheStore extends ArrayStore
{
    public ?string $generationFailure = null;

    #[Override]
    public function lock($name, $seconds = 0, $owner = null)
    {
        $namespaceLock = 'capell.cache.increment.' . hash('sha256', 'raw:capell.cache.generation.' . config('capell.cache_tag', 'capell-app'));
        throw_if($this->generationFailure === 'lock' && $name === $namespaceLock, LockTimeoutException::class);

        return parent::lock($name, $seconds, $owner);
    }

    #[Override]
    public function get($key): mixed
    {
        throw_if($this->generationFailure === 'read' && str_starts_with($key, 'capell.cache.generation.'), RuntimeException::class, 'Generation read failed.');

        return parent::get($key);
    }

    #[Override]
    public function increment($key, $value = 1): int|false
    {
        if (str_starts_with($key, 'capell.cache.') && $this->generationFailure !== null) {
            throw_if($this->generationFailure === 'throw', RuntimeException::class, 'Generation increment failed.');

            return $this->generationFailure === 'unacknowledged-increment'
                ? ((int) $this->get($key)) + 1
                : false;
        }

        return parent::increment($key, $value);
    }

    #[Override]
    public function forever($key, $value): bool
    {
        if (str_starts_with($key, 'capell.cache.') && $this->generationFailure !== null) {
            throw_if($this->generationFailure === 'throw-write', RuntimeException::class, 'Generation write failed.');

            return $this->generationFailure === 'unacknowledged-write';
        }

        return parent::forever($key, $value);
    }

    #[Override]
    public function forget($key): bool
    {
        // Keep old bytes to prove namespace invalidation, rather than deletion.
        return false;
    }

    /** @return array<string, mixed> */
    public function entries(): array
    {
        $entries = [];
        foreach (array_keys($this->storage) as $key) {
            $entries[$key] = $this->get($key);
        }

        return $entries;
    }
}
