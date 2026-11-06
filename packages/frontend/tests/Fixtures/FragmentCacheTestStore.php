<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Override;
use RuntimeException;

final class FragmentCacheTestStore extends ArrayStore
{
    public int $lockCalls = 0;

    public int $putCalls = 0;

    public bool $failMapWrites = false;

    public bool $failFragmentWrites = false;

    public ?string $namespaceWriteFailure = null;

    public ?string $fragmentDeletionFailure = null;

    public bool $failLocks = false;

    public ?Closure $beforeMapWrite = null;

    #[Override]
    public function lock($name, $seconds = 0, $owner = null)
    {
        $this->lockCalls++;

        throw_if($this->failLocks, LockTimeoutException::class);

        return parent::lock($name, $seconds, $owner);
    }

    #[Override]
    public function put($key, $value, $seconds): bool
    {
        $this->putCalls++;

        if ($key === 'fragment:namespace' && $this->namespaceWriteFailure !== null) {
            throw_if($this->namespaceWriteFailure === 'throw', RuntimeException::class, 'Namespace write failed.');

            return $this->namespaceWriteFailure === 'unacknowledged';
        }

        if (is_string($key) && str_ends_with($key, ':surrogate:map')) {
            if ($this->beforeMapWrite instanceof Closure) {
                $callback = $this->beforeMapWrite;
                $this->beforeMapWrite = null;
                $callback();
            }

            if ($this->failMapWrites) {
                return false;
            }
        }

        if (is_string($key) && $this->failFragmentWrites && $this->isFragmentValueKey($key)) {
            return false;
        }

        return parent::put($key, $value, $seconds);
    }

    #[Override]
    public function forget($key): bool
    {
        if (is_string($key) && $this->isFragmentValueKey($key) && $this->fragmentDeletionFailure !== null) {
            throw_if($this->fragmentDeletionFailure === 'throw', RuntimeException::class, 'Fragment deletion failed.');

            return false;
        }

        return parent::forget($key);
    }

    private function isFragmentValueKey(string $key): bool
    {
        return str_contains($key, ':value:') || str_contains($key, ':origin-value:');
    }
}
