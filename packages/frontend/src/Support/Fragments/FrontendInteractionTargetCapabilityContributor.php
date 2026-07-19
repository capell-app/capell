<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Fragments;

use Capell\Core\Contracts\InteractionTargetCapabilityContributor;
use Capell\Core\Enums\InteractionTargetType;

final readonly class FrontendInteractionTargetCapabilityContributor implements InteractionTargetCapabilityContributor
{
    public function __construct(
        private PublicFragmentUrlResolverRegistry $fragmentUrlResolvers,
    ) {}

    public function supports(InteractionTargetType $targetType): bool
    {
        return $targetType === InteractionTargetType::Fragment
            && $this->fragmentUrlResolvers->hasResolvers();
    }
}
