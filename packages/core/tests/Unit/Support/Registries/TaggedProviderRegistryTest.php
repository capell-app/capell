<?php

declare(strict_types=1);

use Capell\Core\Support\Registries\TaggedProviderRegistry;

it('resolves valid tagged providers lazily in registration order', function (): void {
    $resolutions = 0;

    app()->bind('test.tagged-provider.first', function () use (&$resolutions): TaggedProviderTestContract {
        $resolutions++;

        return new TaggedProviderTestImplementation('first');
    });
    app()->bind('test.tagged-provider.invalid', fn (): stdClass => new stdClass);
    app()->bind('test.tagged-provider.second', function () use (&$resolutions): TaggedProviderTestContract {
        $resolutions++;

        return new TaggedProviderTestImplementation('second');
    });
    app()->tag([
        'test.tagged-provider.first',
        'test.tagged-provider.invalid',
        'test.tagged-provider.second',
    ], 'test.tagged-providers');

    $registry = new TaggedProviderTestRegistry(
        TaggedProviderRegistry::tagged(app(), 'test.tagged-providers'),
    );

    expect($resolutions)->toBe(0)
        ->and(array_map(
            fn (TaggedProviderTestContract $provider): string => $provider->name(),
            $registry->all(),
        ))->toBe(['first', 'second'])
        ->and($resolutions)->toBe(2);
});

it('supports direct construction with an iterable of providers', function (): void {
    $registry = new TaggedProviderTestRegistry([
        new TaggedProviderTestImplementation('first'),
        new stdClass,
        new TaggedProviderTestImplementation('second'),
    ]);

    expect(array_map(
        fn (TaggedProviderTestContract $provider): string => $provider->name(),
        $registry->all(),
    ))->toBe(['first', 'second']);
});

interface TaggedProviderTestContract
{
    public function name(): string;
}

final readonly class TaggedProviderTestImplementation implements TaggedProviderTestContract
{
    public function __construct(private string $name) {}

    public function name(): string
    {
        return $this->name;
    }
}

/** @extends TaggedProviderRegistry<TaggedProviderTestContract> */
final class TaggedProviderTestRegistry extends TaggedProviderRegistry
{
    /** @param iterable<mixed> $providers */
    public function __construct(iterable $providers)
    {
        parent::__construct($providers, TaggedProviderTestContract::class);
    }

    /** @return list<TaggedProviderTestContract> */
    public function all(): array
    {
        return $this->providers();
    }
}
