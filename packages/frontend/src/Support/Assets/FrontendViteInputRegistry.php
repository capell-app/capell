<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use InvalidArgumentException;

final class FrontendViteInputRegistry
{
    /** @var array<string, string> */
    private array $inputs = [];

    public function register(string $input, string $package): void
    {
        throw_if($input === '' || str_starts_with($input, '/') || str_contains($input, '..'), InvalidArgumentException::class, 'Frontend Vite inputs must be safe application-relative paths.');

        $this->inputs[$package . '|' . $input] = $input;
    }

    /** @return array<int, string> */
    public function all(): array
    {
        $inputs = array_values(array_unique($this->inputs));
        sort($inputs);

        return $inputs;
    }
}
