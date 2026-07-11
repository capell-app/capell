<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

final class SurrogateKeyNormalizer
{
    /**
     * @param  array<string>  $surrogateKeys
     * @return array<string>
     */
    public static function normalize(array $surrogateKeys): array
    {
        return array_values(array_unique(array_filter(
            $surrogateKeys,
            fn (string $surrogateKey): bool => preg_match('/\A[A-Za-z0-9_-]+\z/', $surrogateKey) === 1,
        )));
    }
}
