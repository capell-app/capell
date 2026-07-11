<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\PackageCapability;
use Override;
use Spatie\LaravelData\Data;

final class PackageCapabilityNodeData extends Data
{
    public function __construct(
        public readonly string $packageName,
        public readonly PackageCapability $capability,
        public readonly string $source,
        public readonly bool $explicit,
        public readonly ?string $reason = null,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'packageName' => $this->packageName,
            'capability' => $this->capability->value,
            'source' => $this->source,
            'explicit' => $this->explicit,
            'reason' => $this->reason,
        ];
    }
}
