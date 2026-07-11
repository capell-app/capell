<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Closure;

final class ExtensionOperationsRequestCache
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function remember(string $key, Closure $resolver): mixed
    {
        if (app()->runningInConsole()) {
            return $resolver();
        }

        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        return $this->items[$key] = $resolver();
    }

    public function forget(?string $key = null): void
    {
        if ($key === null) {
            $this->items = [];

            return;
        }

        unset($this->items[$key]);
    }
}
