<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Registries\TaggedProviderRegistry;

/** @extends TaggedProviderRegistry<TaggedProviderTestContract> */
final class TaggedProviderTestRegistry extends TaggedProviderRegistry
{
    /** @param iterable<mixed> $providers */
    public function __construct(iterable $providers)
    {
        parent::__construct($providers, TaggedProviderTestContract::class);
    }

    /** @return list<TaggedProviderTestContract> */
    public function all(): array
    {
        return $this->providers();
    }
}
