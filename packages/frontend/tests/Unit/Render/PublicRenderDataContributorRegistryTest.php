<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Models\Page;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use Capell\Frontend\Support\Bootstrap\FrontendEventBootstrapper;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

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
    ], new ExtensionContributionReceiptRegistry);

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
    $value = new readonly class(new class extends Model
    {
        use HasFactory;
    })
    {

        public function __construct(private Model $model) {}

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

it('preserves a typed Spatie Data value as a closed public object', function (): void {
    $value = new class('Tea') extends Data
    {
        public function __construct(public string $name) {}
    };

    $contribution = new PublicRenderDataContributionData($value);

    expect($contribution->value)->toEqual((object) ['name' => 'Tea']);
});

it('rejects an Eloquent model nested in a typed Spatie Data value before transformation', function (): void {
    $value = new class(new Page) extends Data
    {
        public function __construct(public Page $model) {}
    };

    new PublicRenderDataContributionData($value);
})->throws(RuntimeException::class, 'Eloquent models');

it('rejects an Eloquent model hidden by JsonSerializable before transformation', function (): void {
    $value = new readonly class(new Page) implements JsonSerializable
    {
        public function __construct(private Page $model) {}

        public function jsonSerialize(): array
        {
            return ['model' => $this->model->toArray()];
        }
    };

    new PublicRenderDataContributionData($value);
})->throws(RuntimeException::class, 'Eloquent models');

it('rejects an Eloquent model yielded by Traversable before transformation', function (): void {
    $value = new readonly class(new Page) implements IteratorAggregate
    {
        public function __construct(private Page $model) {}

        public function getIterator(): Traversable
        {
            yield 'model' => $this->model;
        }
    };

    new PublicRenderDataContributionData($value);
})->throws(RuntimeException::class, 'Eloquent models');

it('fails closed for public and hidden cyclic object graphs', function (bool $hidden): void {
    if (! $hidden) {
        $value = new stdClass;
        $value->self = $value;
    } else {
        $node = new stdClass;
        $value = new class
        {
            public stdClass $node;
        };
        $value->node = $node;
        $node->owner = $value;
    }

    new PublicRenderDataContributionData($value);
})->with([false, true])->throws(RuntimeException::class, 'cyclic object graphs');

it('reuses a false support decision between metadata and value preparation', function (): void {
    $active = false;
    $supportsCalls = 0;
    $contributor = new class($active, $supportsCalls) implements PublicRenderDataContributor
    {
        public function __construct(private bool &$active, private int &$supportsCalls) {}

        public function key(): string
        {
            return 'support-false-to-true';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            $this->supportsCalls++;

            return $this->active;
        }

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('support-v1');
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) ['value' => 'unexpected']);
        }

        public function cacheDependencyModelTypes(): array
        {
            return [];
        }
    };
    $registry = new PublicRenderDataContributorRegistry([$contributor], new ExtensionContributionReceiptRegistry);
    $context = new FrontendRenderContextData(null, null, null, null, null);

    $registry->metadata($context);
    $active = true;

    expect($registry->prepare($context)->values)->toBe([])
        ->and($supportsCalls)->toBe(1);
});

it('reuses a true support decision between metadata and value preparation', function (): void {
    $active = true;
    $supportsCalls = 0;
    $contributor = new class($active, $supportsCalls) implements PublicRenderDataContributor
    {
        public function __construct(private bool &$active, private int &$supportsCalls) {}

        public function key(): string
        {
            return 'support-true-to-false';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            $this->supportsCalls++;

            return $this->active;
        }

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('support-v1');
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) ['value' => 'expected']);
        }

        public function cacheDependencyModelTypes(): array
        {
            return [];
        }
    };
    $registry = new PublicRenderDataContributorRegistry([$contributor], new ExtensionContributionReceiptRegistry);
    $context = new FrontendRenderContextData(null, null, null, null, null);

    $registry->metadata($context);
    $active = false;

    expect($registry->prepare($context)->values)->toHaveKey('support-true-to-false')
        ->and($supportsCalls)->toBe(1);
});

it('fails the real bootstrap when a contributor dependency is undeclared', function (): void {
    $registry = resolve(PublicRenderDataContributorRegistry::class);
    $registry->register(new class implements PublicRenderDataContributor
    {
        public function key(): string
        {
            return 'mismatched';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            return true;
        }

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            $page = new Page;
            $page->setAttribute('id', 1);

            return new PublicRenderDataContributionMetadataData(
                'mismatch-v1',
                cacheDependencies: [PublicRenderDataCacheDependencyData::forModel($page)],
            );
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) []);
        }

        public function cacheDependencyModelTypes(): array
        {
            return [];
        }
    });

    resolve(FrontendEventBootstrapper::class)->boot();
    $registry->metadata(new FrontendRenderContextData(null, null, null, null, null));
})->throws(InvalidArgumentException::class, 'undeclared cache dependency');

it('fails the real bootstrap on an invalid dependency model list', function (): void {
    resolve(PublicRenderDataContributorRegistry::class)->register(new class implements PublicRenderDataContributor
    {
        public function key(): string
        {
            return 'invalid-model-list';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            return true;
        }

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('invalid-model-list-v1');
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) []);
        }

        public function cacheDependencyModelTypes(): array
        {
            /** @var class-string<Model> $invalidModelType */
            $invalidModelType = implode('', ['std', 'Class']);

            return [$invalidModelType];
        }
    });

    resolve(FrontendEventBootstrapper::class)->boot();
})->throws(InvalidArgumentException::class, 'invalid dependency model');

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

    new PublicRenderDataContributorRegistry([$contributor], new ExtensionContributionReceiptRegistry);
})->throws(InvalidArgumentException::class, 'stable identifiers');

it('records contributor registration through the CAP-0467 receipt registry', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    $contributor = new class implements PublicRenderDataContributor
    {
        public function key(): string
        {
            return 'receipt.example';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            return false;
        }

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('receipt-v1');
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) []);
        }

        public function cacheDependencyModelTypes(): array
        {
            return [];
        }
    };

    new PublicRenderDataContributorRegistry([$contributor], $receipts);

    expect($receipts->all())->toHaveCount(1)
        ->and($receipts->all()[0]->type)->toBe(ExtensionContributionType::PublicRenderData)
        ->and($receipts->all()[0]->key)->toBe('receipt.example')
        ->and($receipts->all()[0]->implementation)->toBe($contributor::class);
});
