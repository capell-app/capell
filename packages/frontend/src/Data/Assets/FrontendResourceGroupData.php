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
        throw_if(preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $key) !== 1, InvalidArgumentException::class, 'Frontend resource group key must be stable and machine-readable.');

        throw_if(trim($label) === '', InvalidArgumentException::class, 'Frontend resource group label cannot be blank.');

        throw_if(preg_match('/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/', $package) !== 1, InvalidArgumentException::class, 'Frontend resource group package must be a valid Composer package name.');

        foreach ($resources as $resource) {
            throw_unless($resource instanceof FrontendResourceData, InvalidArgumentException::class, 'Frontend resource groups may contain only typed resources.');

            throw_if($resource->package !== $package, InvalidArgumentException::class, 'A frontend resource group package must own every resource.');
        }

        $handles = array_map(static fn (FrontendResourceData $resource): string => $resource->handle, $resources);

        throw_if(count($handles) !== count(array_unique($handles)), InvalidArgumentException::class, 'Frontend resource handles must be unique within a group.');
    }
}
