<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class FrontendResourceContributionData extends Data
{
    /** @param array<int, FrontendResourceActivationData> $activations */
    public function __construct(
        public readonly FrontendResourceData $resource,
        public readonly array $activations = [],
    ) {
        foreach ($activations as $activation) {
            if (! $activation instanceof FrontendResourceActivationData) {
                throw new InvalidArgumentException('Frontend resource contributions require typed activations.');
            }
        }
    }
}
