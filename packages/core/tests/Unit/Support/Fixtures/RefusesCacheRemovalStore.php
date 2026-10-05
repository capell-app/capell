<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Fixtures;

use Illuminate\Cache\ArrayStore;
use Override;

final class RefusesCacheRemovalStore extends ArrayStore
{
    public bool $refuseRemoval = false;

    public int $removalAttempts = 0;

    public function __construct(private readonly bool $reportedSuccess, private readonly bool $removeValue, private readonly bool $repopulate = false)
    {
        parent::__construct();
    }

    #[Override]
    /** @param string $key */
    public function forget(mixed $key): bool
    {
        $this->removalAttempts++;
        if (! $this->refuseRemoval) {
            return parent::forget($key);
        }

        if ($this->removeValue) {
            parent::forget($key);
        }

        if ($this->repopulate) {
            $this->put($key, ['fresh'], 60);
        }

        return $this->reportedSuccess;
    }
}
