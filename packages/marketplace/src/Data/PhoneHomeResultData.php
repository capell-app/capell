<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

final readonly class PhoneHomeResultData
{
    public function __construct(
        public bool $successful,
        public ?string $failureMessage = null,
    ) {}
}
