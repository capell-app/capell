<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

class VersionAudit extends Data
{
    /**
     * @param  array<int, string>  $composerOnly
     * @param  array<int, string>  $ledgerOnly
     * @param  array<string, array{from: string, to: string}>  $downgrades
     */
    public function __construct(
        public readonly array $composerOnly,
        public readonly array $ledgerOnly,
        public readonly array $downgrades,
    ) {}

    public function hasIssues(): bool
    {
        return $this->composerOnly !== [] || $this->ledgerOnly !== [] || $this->downgrades !== [];
    }
}
