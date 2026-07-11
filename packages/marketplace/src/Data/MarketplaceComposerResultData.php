<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

final readonly class MarketplaceComposerResultData
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
