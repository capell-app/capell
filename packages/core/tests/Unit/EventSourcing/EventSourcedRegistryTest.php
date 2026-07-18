<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Capell\Core\EventSourcing\Exceptions\EventSourcingException;
use Capell\Core\EventSourcing\Serializers\PageStateSerializer;
use Capell\Core\EventSourcing\Support\EventSourcedRegistry;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;

it('registers models and replaces an existing registration', function (): void {
    $registry = new EventSourcedRegistry;
    $page = new Page;

    $registry->register(Page::class, PageAggregate::class, PageStateSerializer::class);

    expect($registry->isRegistered(Page::class))->toBeTrue()
        ->and($registry->isRegistered($page))->toBeTrue()
        ->and($registry->aggregateFor($page))->toBe(PageAggregate::class)
        ->and($registry->serializerFor(Page::class))->toBeInstanceOf(PageStateSerializer::class)
        ->and($registry->registeredModels())->toBe([Page::class]);

    $registry->register(Page::class, PageAggregate::class, EventSourcedRegistryTestSerializer::class);

    expect($registry->serializerFor($page))->toBeInstanceOf(EventSourcedRegistryTestSerializer::class)
        ->and($registry->registeredModels())->toBe([Page::class]);
});

it('rejects models without a registration', function (): void {
    $registry = new EventSourcedRegistry;

    expect($registry->isRegistered(Page::class))->toBeFalse()
        ->and(fn (): string => $registry->aggregateFor(Page::class))
        ->toThrow(EventSourcingException::class, sprintf('Model [%s] is not registered for event sourcing.', Page::class));
});

final class EventSourcedRegistryTestSerializer implements EventSourcedStateSerializer
{
    public function capture(Model $model): array
    {
        return [];
    }

    public function restore(Model $model, array $state): void {}
}
