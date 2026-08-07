<?php

declare(strict_types=1);

use Capell\Core\Actions\PublishOutboundEventAction;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Events\OutboundEventPublished;
use Capell\Core\Exceptions\OutboundEventRegistrationException;
use Capell\Core\Exceptions\UnknownOutboundEventException;
use Capell\Core\Support\OutboundEventRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelData\Data;

it('registers and resolves typed outbound event definitions', function (): void {
    $registry = new OutboundEventRegistry;
    $definition = new OutboundEventDefinitionData(
        name: 'editorial.article-published',
        version: 1,
        payloadClass: PageTypeData::class,
        description: 'An article was published.',
        ownerPackage: 'vendor/editorial',
    );

    expect($registry->register($definition))->toBe($registry)
        ->and($registry->definition($definition->name))->toBe($definition)
        ->and($registry->has($definition->name))->toBeTrue();
});

it('rejects duplicate, malformed, and untyped outbound event definitions', function (): void {
    $registry = new OutboundEventRegistry;
    $definition = new OutboundEventDefinitionData(
        name: 'editorial.article-published',
        version: 1,
        payloadClass: PageTypeData::class,
        description: 'An article was published.',
        ownerPackage: 'vendor/editorial',
    );

    $registry->register($definition);

    expect(fn (): OutboundEventRegistry => $registry->register($definition))
        ->toThrow(OutboundEventRegistrationException::class, 'already registered');

    expect(fn (): OutboundEventRegistry => $registry->register(new OutboundEventDefinitionData(
        name: 'invalid',
        version: 1,
        payloadClass: PageTypeData::class,
        description: 'Invalid name.',
        ownerPackage: 'vendor/editorial',
    )))->toThrow(OutboundEventRegistrationException::class, 'vendor-package.event-name');

    expect(fn (): OutboundEventRegistry => $registry->register(new OutboundEventDefinitionData(
        name: 'editorial.invalid-payload',
        version: 1,
        payloadClass: stdClass::class,
        description: 'Invalid payload.',
        ownerPackage: 'vendor/editorial',
    )))->toThrow(OutboundEventRegistrationException::class, 'must extend');
});

it('publishes a validated event with an id and timestamp', function (): void {
    Event::fake();
    $registry = new OutboundEventRegistry;
    $registry->register(new OutboundEventDefinitionData(
        name: 'editorial.article-published',
        version: 2,
        payloadClass: PageTypeData::class,
        description: 'An article was published.',
        ownerPackage: 'vendor/editorial',
    ));

    app()->instance(OutboundEventRegistry::class, $registry);

    $published = PublishOutboundEventAction::run(
        'editorial.article-published',
        new PageTypeData(name: 'article', model: stdClass::class, label: 'Article'),
    );

    expect($published)->toBeInstanceOf(OutboundEventPublished::class)
        ->and($published->definition->version)->toBe(2)
        ->and($published->eventId)->not->toBe('')
        ->and($published->occurredAt)->toBeInstanceOf(CarbonImmutable::class);

    Event::assertDispatched(OutboundEventPublished::class);
});

it('fails closed for unknown events and payload mismatches', function (): void {
    $registry = new OutboundEventRegistry;
    $registry->register(new OutboundEventDefinitionData(
        name: 'editorial.article-published',
        version: 1,
        payloadClass: PageTypeData::class,
        description: 'An article was published.',
        ownerPackage: 'vendor/editorial',
    ));
    app()->instance(OutboundEventRegistry::class, $registry);

    expect(fn (): OutboundEventPublished => PublishOutboundEventAction::run(
        'editorial.missing',
        new PageTypeData(name: 'article', model: stdClass::class),
    ))->toThrow(UnknownOutboundEventException::class, 'is not registered');

    expect(fn (): OutboundEventPublished => PublishOutboundEventAction::run(
        'editorial.article-published',
        new class extends Data
        {
            public function __construct(public string $value = 'wrong') {}
        },
    ))->toThrow(UnknownOutboundEventException::class, 'expects payload');
});

it('publishes as a no-op when nothing listens for the outbound event', function (): void {
    // Core performs no HTTP: with no delivery package installed there is no
    // listener bound, and publishing must still succeed rather than fail or
    // block. This is what makes it safe for a package to publish unconditionally.
    $registry = new OutboundEventRegistry;
    $registry->register(new OutboundEventDefinitionData(
        name: 'editorial.article-published',
        version: 1,
        payloadClass: PageTypeData::class,
        description: 'An article was published.',
        ownerPackage: 'vendor/editorial',
    ));
    app()->instance(OutboundEventRegistry::class, $registry);
    Event::forget(OutboundEventPublished::class);

    $published = PublishOutboundEventAction::run(
        'editorial.article-published',
        new PageTypeData(name: 'article', model: stdClass::class, label: 'Article'),
    );

    expect($published)->toBeInstanceOf(OutboundEventPublished::class)
        ->and($published->definition->name)->toBe('editorial.article-published');
});

it('rejects registration after the outbound event registry is frozen', function (): void {
    $registry = new OutboundEventRegistry;
    $registry->freeze();

    expect(fn (): OutboundEventRegistry => $registry->register(new OutboundEventDefinitionData(
        name: 'editorial.article-published',
        version: 1,
        payloadClass: PageTypeData::class,
        description: 'An article was published.',
        ownerPackage: 'vendor/editorial',
    )))->toThrow(OutboundEventRegistrationException::class, 'cannot be registered after boot');
});
