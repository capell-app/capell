<?php

declare(strict_types=1);

namespace Capell\Core\Data;

final readonly class PackageRequirementData
{
    public function __construct(
        public string $package,
        public string $requirement,
    ) {}

    /** @return array{package: string, requirement: string} */
    public function toArray(): array
    {
        return ['package' => $this->package, 'requirement' => $this->requirement];
    }
}
