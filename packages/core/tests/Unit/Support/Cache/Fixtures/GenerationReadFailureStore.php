<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Cache\Fixtures;

use Illuminate\Cache\ArrayStore;
use Override;
use RuntimeException;

final class GenerationReadFailureStore extends ArrayStore
{
    public bool $failPatternWrites = false;

    public ?string $failNextRead = null;

    #[Override]
    public function get($key): mixed
    {
        if ($key === $this->failNextRead) {
            $this->failNextRead = null;

            throw new RuntimeException('Generation read failed.');
        }

        return parent::get($key);
    }

    #[Override]
    public function put($key, $value, $seconds): bool
    {
        if ($this->failPatternWrites && str_starts_with($key, 'capell.cache.pattern-generation.')) {
            return false;
        }

        return parent::put($key, $value, $seconds);
    }

    #[Override]
    public function increment($key, $value = 1): int|false
    {
        if ($this->failPatternWrites && str_starts_with($key, 'capell.cache.pattern-generation.')) {
            return false;
        }

        return parent::increment($key, $value);
    }
}
