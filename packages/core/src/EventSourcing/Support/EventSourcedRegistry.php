<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Support;

use Capell\Core\EventSourcing\Aggregates\CapellAggregateRoot;
use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Capell\Core\EventSourcing\Exceptions\EventSourcingException;
use Illuminate\Database\Eloquent\Model;

use function resolve;

/**
 * Registry mapping a model class to its aggregate + serializer. Registered as
 * a singleton in the core service provider; this is the extension point that
 * lets packages (and future adopters such as Layout/Blueprint) register their
 * own aggregates without touching core.
 */
final class EventSourcedRegistry
{
    /**
     * @var array<class-string<Model>, array{aggregate: class-string<CapellAggregateRoot>, serializer: class-string<EventSourcedStateSerializer>}>
     */
    private array $registrations = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<CapellAggregateRoot>  $aggregateClass
     * @param  class-string<EventSourcedStateSerializer>  $serializerClass
     */
    public function register(string $modelClass, string $aggregateClass, string $serializerClass): void
    {
        $this->registrations[$modelClass] = [
            'aggregate' => $aggregateClass,
            'serializer' => $serializerClass,
        ];
    }

    /**
     * @param  class-string|Model  $model
     */
    public function isRegistered(Model|string $model): bool
    {
        return array_key_exists($this->normalise($model), $this->registrations);
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
     * @return list<class-string<Model>>
     */
    public function registeredModels(): array
    {
        return array_keys($this->registrations);
    }

    /**
     * @param  class-string|Model  $model
     * @return array{aggregate: class-string<CapellAggregateRoot>, serializer: class-string<EventSourcedStateSerializer>}
     */
    private function registration(Model|string $model): array
    {
        $modelClass = $this->normalise($model);

        if (! array_key_exists($modelClass, $this->registrations)) {
            throw new EventSourcingException(sprintf(
                'Model [%s] is not registered for event sourcing.',
                $modelClass,
            ));
        }

        return $this->registrations[$modelClass];
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
