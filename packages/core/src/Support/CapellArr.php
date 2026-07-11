<?php

declare(strict_types=1);

namespace Capell\Core\Support;

final class CapellArr
{
    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
