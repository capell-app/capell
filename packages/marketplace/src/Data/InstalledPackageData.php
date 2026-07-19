<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Override;
use Spatie\LaravelData\Data;

final class InstalledPackageData extends Data
{
    /**
     * @param  array<string, mixed>  $marketplaceActivation
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $version,
        public ?string $path,
        public bool $lifecycleInstalled = true,
        public bool $paid = false,
        public ?string $licenceStatus = null,
        public ?bool $runtimeAllowed = null,
        public array $marketplaceActivation = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'label' => $this->label,
            'composer_name' => $this->name,
            'version' => $this->version,
            'path' => $this->path,
            'lifecycle_installed' => $this->lifecycleInstalled,
            'paid' => $this->paid,
            'licence_status' => $this->licenceStatus,
            'runtime_allowed' => $this->runtimeAllowed,
            'marketplace_activation' => $this->marketplaceActivation,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
