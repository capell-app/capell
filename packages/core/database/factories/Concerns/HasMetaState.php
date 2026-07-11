<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories\Concerns;

use Illuminate\Support\Arr;

trait HasMetaState
{
    /**
     * @param  array<string, mixed>|string  $input
     */
    public function setMetaState(string $key, array|string $input, mixed $value = null): static
    {
        return $this->state(function (array $attributes) use ($key, $input, $value): array {
            $existing = $attributes[$key] ?? [];
            $data = is_string($input)
                ? Arr::undot([$input => $value])
                : Arr::undot($input);

            return [
                $key => array_replace_recursive($existing, $data),
            ];
        });
    }
}
