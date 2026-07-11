<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionCapabilityReportData extends Data
{
    /**
     * @param  list<ExtensionInstallImpactData>  $packages
     * @param  list<string>  $surfaces
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $packages,
        public readonly array $surfaces,
        public readonly array $warnings,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'packages' => array_map(
                static fn (ExtensionInstallImpactData $package): array => $package->toArray(),
                $this->packages,
            ),
            'surfaces' => $this->surfaces,
            'warnings' => $this->warnings,
        ];
    }
}
