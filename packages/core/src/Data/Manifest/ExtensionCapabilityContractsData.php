<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Capell\Core\Enums\CapabilityContract;
use Override;
use Spatie\LaravelData\Data;

/**
 * The Core capability contracts a package provides and consumes, with the major
 * version of each, from capell.json capabilityContracts. ManifestValidator has
 * already rejected unknown keys and unsupported versions.
 */
final class ExtensionCapabilityContractsData extends Data
{
    /**
     * @param  array<string, int>  $provides  capability key => major version
     * @param  array<string, int>  $consumes  capability key => major version
     */
    public function __construct(
        public readonly array $provides = [],
        public readonly array $consumes = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            provides: self::versions($data['provides'] ?? []),
            consumes: self::versions($data['consumes'] ?? []),
        );
    }

    public function provides(CapabilityContract $capability): bool
    {
        return isset($this->provides[$capability->value]);
    }

    public function consumes(CapabilityContract $capability): bool
    {
        return isset($this->consumes[$capability->value]);
    }

    /** @return array{provides?: array<string, int>, consumes?: array<string, int>} */
    #[Override]
    public function toArray(): array
    {
        return array_filter(['provides' => $this->provides, 'consumes' => $this->consumes], static fn (array $versions): bool => $versions !== []);
    }

    /** @return array<string, int> */
    private static function versions(mixed $versions): array
    {
        if (! is_array($versions)) {
            return [];
        }

        $known = [];

        foreach ($versions as $key => $version) {
            if (is_string($key) && is_int($version) && CapabilityContract::tryFrom($key) instanceof CapabilityContract) {
                $known[$key] = $version;
            }
        }

        ksort($known);

        return $known;
    }
}
