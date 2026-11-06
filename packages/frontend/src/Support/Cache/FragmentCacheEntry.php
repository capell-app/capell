<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

final readonly class FragmentCacheEntry
{
    /** @param list<string> $surrogateKeys */
    public function __construct(public mixed $value, public array $surrogateKeys) {}
}
