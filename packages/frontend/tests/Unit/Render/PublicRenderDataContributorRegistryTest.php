<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;

it('composes keyed public data deterministically and fingerprints all active contributors', function (): void {
    $context = new FrontendRenderContextData(null, null, null, null, null);
    $registry = new PublicRenderDataContributorRegistry([
        new class implements PublicRenderDataContributor
        {
            public function key(): string
            {
                return 'zeta';
            }

            public function supports(FrontendRenderContextData $context): bool
            {
                return true;
            }

            public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
            {
                return new PublicRenderDataContributionData((object) ['value' => 'z'], 'z-v1');
            }
        },
        new class implements PublicRenderDataContributor
        {
            public function key(): string
            {
                return 'alpha';
            }

            public function supports(FrontendRenderContextData $context): bool
            {
                return true;
            }

            public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
            {
                return new PublicRenderDataContributionData((object) ['value' => 'a'], 'a-v1');
            }
        },
    ]);

    $prepared = $registry->prepare($context);

    expect(array_keys($prepared->values))->toBe(['alpha', 'zeta'])
        ->and($prepared->fingerprint)->toBe(hash('sha256', json_encode(['alpha' => 'a-v1', 'zeta' => 'z-v1'], JSON_THROW_ON_ERROR)));
});

it('hydrates contributor values into public page render data before Blade', function (): void {
    resolve(PublicRenderDataContributorRegistry::class)->register(new class implements PublicRenderDataContributor
    {
        public function key(): string
        {
            return 'example.catalogue';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            return true;
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData(
                value: (object) ['products' => [['name' => 'Tea', 'price' => '4.00', 'available' => true]]],
                fingerprint: 'catalogue-v1',
                surrogateKeys: ['catalogue-tea'],
            );
        }
    });

    $renderData = BuildPublicPageRenderDataAction::run(new FrontendRenderContextData(null, null, null, null, null));

    expect($renderData->extensionData('example.catalogue'))->toEqual((object) [
        'products' => [['name' => 'Tea', 'price' => '4.00', 'available' => true]],
    ])->and($renderData->surrogateKeys)->toContain('catalogue-tea');
});

it('fails closed for non-serialisable public values', function (): void {
    $value = new class
    {
        public mixed $callback;
    };
    $value->callback = static fn (): string => 'private';

    new PublicRenderDataContributionData($value, 'invalid');
})->throws(RuntimeException::class, 'serialisable public data');

it('rejects duplicate and invalid contributor keys', function (): void {
    $contributor = new class implements PublicRenderDataContributor
    {
        public function key(): string
        {
            return 'invalid key';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            return true;
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) [], 'v1');
        }
    };

    new PublicRenderDataContributorRegistry([$contributor]);
})->throws(InvalidArgumentException::class, 'stable identifiers');
