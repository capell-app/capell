<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Closure;
use Illuminate\Cache\Repository;
use Override;

final class InterleavedFragmentRepository extends Repository
{
    public ?Closure $beforeMapWrite = null;

    #[Override]
    public function put($key, $value, $ttl = null): bool
    {
        if (is_string($key) && str_ends_with($key, ':surrogate:map') && $this->beforeMapWrite instanceof Closure) {
            $callback = $this->beforeMapWrite;
            $this->beforeMapWrite = null;
            $callback();
        }

        return parent::put($key, $value, $ttl);
    }
}
