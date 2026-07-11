<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

class FrontendComponentData
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $props
     */
    public function __construct(
        public string $key,
        public string $component,
        public array $aliases = [],
        public array $props = [],
    ) {}

    /** @return list<string> */
    public function references(): array
    {
        return array_values(array_unique([
            $this->key,
            $this->component,
            ...$this->aliases,
        ]));
    }
}
