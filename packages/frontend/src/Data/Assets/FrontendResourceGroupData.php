<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class FrontendResourceGroupData extends Data
{
    /**
     * @param  array<int, FrontendResourceData>  $resources
     * @param  array<int, FrontendResourceHintData>  $hints
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $package,
        public readonly array $resources = [],
        public readonly array $hints = [],
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $key) !== 1) {
            throw new InvalidArgumentException('Frontend resource group key must be stable and machine-readable.');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException('Frontend resource group label cannot be blank.');
        }

        if (preg_match('/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/', $package) !== 1) {
            throw new InvalidArgumentException('Frontend resource group package must be a valid Composer package name.');
        }

        foreach ($resources as $resource) {
            if (! $resource instanceof FrontendResourceData) {
                throw new InvalidArgumentException('Frontend resource groups may contain only typed resources.');
            }

            if ($resource->package !== $package) {
                throw new InvalidArgumentException('A frontend resource group package must own every resource.');
            }
        }

        $handles = array_map(static fn (FrontendResourceData $resource): string => $resource->handle, $resources);

        if (count($handles) !== count(array_unique($handles))) {
            throw new InvalidArgumentException('Frontend resource handles must be unique within a group.');
        }
    }
}
