<?php

declare(strict_types=1);

use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Capell\Core\Tests\Support\TaggedProviderTestContract;
use Capell\Core\Tests\Support\TaggedProviderTestImplementation;
use Capell\Core\Tests\Support\TaggedProviderTestRegistry;

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

it('sees the first contributor added after the registry was resolved with an empty tag', function (): void {
    $registry = new TaggedProviderTestRegistry(TaggedProviderRegistry::tagged(app(), 'test.late-contributor'));
    expect($registry->all())->toBe([]);
    app()->instance('test.late-provider', new TaggedProviderTestImplementation('late'));
    app()->tag(['test.late-provider'], 'test.late-contributor');

    expect(array_map(fn (TaggedProviderTestContract $provider): string => $provider->name(), $registry->all()))->toBe(['late']);
});
