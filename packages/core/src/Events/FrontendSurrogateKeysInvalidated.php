<?php

declare(strict_types=1);

namespace Capell\Core\Events;

final class FrontendSurrogateKeysInvalidated
{
    /** @var list<string> */
    public array $surrogateKeys;

    /**
     * @param  array<int, string>  $surrogateKeys
     */
    public function __construct(array $surrogateKeys)
    {
        $this->surrogateKeys = array_values(array_unique(array_filter(
            $surrogateKeys,
            fn (string $surrogateKey): bool => $surrogateKey !== '',
        )));
    }
}
