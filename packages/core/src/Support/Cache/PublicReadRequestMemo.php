<?php

declare(strict_types=1);

namespace Capell\Core\Support\Cache;

use Closure;

/**
 * Holds read-only page lookups that several render contributors repeat with the
 * same arguments inside one request (breadcrumb ancestors, the first page of a
 * type). Bound as a scoped instance, so it empties between requests and queued
 * jobs, and any Eloquent write empties it so a lookup never outlives a change.
 */
final class PublicReadRequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolve
     * @return TValue
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        return $this->values[$key] = $resolve();
    }

    public function flush(): void
    {
        $this->values = [];
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
