<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Support;

use Capell\Core\EventSourcing\Aggregates\CapellAggregateRoot;
use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Capell\Core\EventSourcing\Exceptions\EventSourcingException;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Database\Eloquent\Model;

use function resolve;

/**
 * Registry mapping a model class to its aggregate + serializer. Registered as
 * a singleton in the core service provider; this is the extension point that
 * lets packages (and future adopters such as Layout/Blueprint) register their
 * own aggregates without touching core.
 */
/** @extends AbstractKeyedRegistry<array{aggregate: class-string<CapellAggregateRoot>, serializer: class-string<EventSourcedStateSerializer>}, class-string> */
final class EventSourcedRegistry extends AbstractKeyedRegistry
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<CapellAggregateRoot>  $aggregateClass
     * @param  class-string<EventSourcedStateSerializer>  $serializerClass
     */
    public function register(string $modelClass, string $aggregateClass, string $serializerClass): void
    {
        $this->setItem($modelClass, [
            'aggregate' => $aggregateClass,
            'serializer' => $serializerClass,
        ]);
    }

    /**
     * @param  class-string|Model  $model
     */
    public function isRegistered(Model|string $model): bool
    {
        return $this->hasItem($this->normalise($model));
    }

    /**
     * @param  class-string|Model  $model
     * @return class-string<CapellAggregateRoot>
     */
    public function aggregateFor(Model|string $model): string
    {
        return $this->registration($model)['aggregate'];
    }

    /**
     * @param  class-string|Model  $model
     */
    public function serializerFor(Model|string $model): EventSourcedStateSerializer
    {
        return resolve($this->registration($model)['serializer']);
    }

    /**
     * @return list<class-string>
     */
    public function registeredModels(): array
    {
        return array_keys($this->allItems());
    }

    /**
     * @param  class-string|Model  $model
     * @return array{aggregate: class-string<CapellAggregateRoot>, serializer: class-string<EventSourcedStateSerializer>}
     */
    private function registration(Model|string $model): array
    {
        $modelClass = $this->normalise($model);

        $registration = $this->getItem($modelClass);

        if ($registration === null) {
            throw new EventSourcingException(sprintf(
                'Model [%s] is not registered for event sourcing.',
                $modelClass,
            ));
        }

        return $registration;
    }

    /**
     * @param  class-string|Model  $model
     * @return class-string
     */
    private function normalise(Model|string $model): string
    {
        return $model instanceof Model ? $model::class : $model;
    }
}
