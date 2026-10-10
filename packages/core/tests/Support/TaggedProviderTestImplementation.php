<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Override;

final readonly class TaggedProviderTestImplementation implements TaggedProviderTestContract
{
    public function __construct(private string $name) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }
}
