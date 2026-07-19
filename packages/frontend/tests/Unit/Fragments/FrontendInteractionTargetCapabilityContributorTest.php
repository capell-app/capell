<?php

declare(strict_types=1);

use Capell\Core\Enums\InteractionTargetType;
use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Support\Fragments\FrontendInteractionTargetCapabilityContributor;
use Capell\Frontend\Support\Fragments\PublicFragmentUrlResolverRegistry;

it('only supports fragment targets when a public fragment resolver exists', function (): void {
    $withoutResolvers = new FrontendInteractionTargetCapabilityContributor(
        new PublicFragmentUrlResolverRegistry([]),
    );
    $resolver = new class implements PublicFragmentUrlResolver
    {
        public function owner(): string
        {
            return 'layout-builder';
        }

        public function url(PublicFragmentReferenceData $reference): string
        {
            return '/_fragments/' . $reference->contentVersion;
        }
    };
    $withResolver = new FrontendInteractionTargetCapabilityContributor(
        new PublicFragmentUrlResolverRegistry([$resolver]),
    );

    expect($withoutResolvers->supports(InteractionTargetType::Fragment))->toBeFalse()
        ->and($withResolver->supports(InteractionTargetType::Fragment))->toBeTrue()
        ->and($withResolver->supports(InteractionTargetType::Widget))->toBeFalse();
});
