<?php

declare(strict_types=1);

use Capell\Core\Actions\PublishOutboundEventAction;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Events\OutboundEventPublished;
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
        ->toThrow(InvalidArgumentException::class, 'already registered');

    expect(fn (): OutboundEventRegistry => $registry->register(new OutboundEventDefinitionData(
        name: 'invalid',
        version: 1,
        payloadClass: PageTypeData::class,
        description: 'Invalid name.',
        ownerPackage: 'vendor/editorial',
    )))->toThrow(InvalidArgumentException::class, 'vendor-package.event-name');

    expect(fn (): OutboundEventRegistry => $registry->register(new OutboundEventDefinitionData(
        name: 'editorial.invalid-payload',
        version: 1,
        payloadClass: stdClass::class,
        description: 'Invalid payload.',
        ownerPackage: 'vendor/editorial',
    )))->toThrow(InvalidArgumentException::class, 'must extend');
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

    $published = (new PublishOutboundEventAction($registry))->handle(
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
    $action = new PublishOutboundEventAction($registry);

    expect(fn (): OutboundEventPublished => $action->handle(
        'editorial.missing',
        new PageTypeData(name: 'article', model: stdClass::class),
    ))->toThrow(InvalidArgumentException::class, 'is not registered');

    expect(fn (): OutboundEventPublished => $action->handle(
        'editorial.article-published',
        new class extends Data
        {
            public function __construct(public string $value = 'wrong') {}
        },
    ))->toThrow(InvalidArgumentException::class, 'expects payload');
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
    )))->toThrow(InvalidArgumentException::class, 'frozen');
});
