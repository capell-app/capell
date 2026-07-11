<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Filament;

use Illuminate\Contracts\Support\Arrayable;

final class RawState
{
    /**
     * @return array<string, mixed>
     */
    public static function array(mixed $state): array
    {
        if ($state instanceof Arrayable) {
            $state = $state->toArray();
        }

        return is_array($state) ? $state : [];
    }
}
