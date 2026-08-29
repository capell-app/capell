<?php

declare(strict_types=1);

use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;
use Illuminate\Database\Eloquent\Model;

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

            public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
            {
                return new PublicRenderDataContributionMetadataData('z-v1');
            }

            public function cacheDependencyModelTypes(): array
            {
                return [];
            }

            public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
            {
                return new PublicRenderDataContributionData((object) ['value' => 'z']);
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

            public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
            {
                return new PublicRenderDataContributionMetadataData('a-v1');
            }

            public function cacheDependencyModelTypes(): array
            {
                return [];
            }

            public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
            {
                return new PublicRenderDataContributionData((object) ['value' => 'a']);
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

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('catalogue-v1', ['catalogue-tea']);
        }

        public function cacheDependencyModelTypes(): array
        {
            return [];
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData(
                value: (object) ['products' => [['name' => 'Tea', 'price' => '4.00', 'available' => true]]],
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

    new PublicRenderDataContributionData($value);
})->throws(RuntimeException::class, 'serialisable public data');

it('fails closed when an arbitrary value hides an Eloquent model', function (): void {
    $value = new class(new class extends Model {})
    {

        private Model $model;

        public function __construct(Model $model)
        {
            $this->model = $model;
        }

        public function model(): Model
        {
            return $this->model;
        }
    };

    new PublicRenderDataContributionData($value);
})->throws(RuntimeException::class, 'Eloquent models');

it('rejects non-Eloquent dependency classes', function (): void {
    new PublicRenderDataCacheDependencyData(stdClass::class, 1);
})->throws(InvalidArgumentException::class, 'Eloquent model class');

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

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('v1');
        }

        public function cacheDependencyModelTypes(): array
        {
            return [];
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) []);
        }
    };

    new PublicRenderDataContributorRegistry([$contributor]);
})->throws(InvalidArgumentException::class, 'stable identifiers');
