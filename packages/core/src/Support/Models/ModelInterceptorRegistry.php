<?php

declare(strict_types=1);

namespace Capell\Core\Support\Models;

use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;

/**
 * Generic trait for registering and creating entities with interceptors.
 * Replace TEntity, TData, TInterceptor in consuming class via type-hints or docblocks.
 */
final class ModelInterceptorRegistry
{
    /**
     * Interceptors by model.
     * Each entry has: class, priority, conditions (array<string, scalar>)
     *
     * @var array<string, array<int, array{class: class-string<object>, priority: int, conditions: array<string, string|int|float|bool>}>>
     */
    protected array $modelInterceptors = [];

    /**
     * Register an interceptor for a given model and key or composite conditions.
     *
     * @param  class-string<object>  $interceptorClass
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     */
    public function registerModelInterceptor(string $model, string $interceptorClass, null|array|string|BackedEnum $key = null, int $priority = 0): void
    {
        $conditions = $this->normalizeKeyConditions($key);

        $this->modelInterceptors[$model] ??= [];

        // If interceptor with same class and conditions exists, update its priority; otherwise append.
        foreach ($this->modelInterceptors[$model] as $index => $entry) {
            if ($entry['class'] === $interceptorClass && $this->conditionsEqual($entry['conditions'], $conditions)) {
                $this->modelInterceptors[$model][$index]['priority'] = $priority;
                $this->sortInterceptors($model);

                return;
            }
        }

        $this->modelInterceptors[$model][] = [
            'class' => $interceptorClass,
            'priority' => $priority,
            'conditions' => $conditions,
        ];

        $this->sortInterceptors($model);
    }

    /**
     * Remove a previously registered interceptor for a given model and key.
     *
     * @param  class-string<object>  $interceptorClass
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     */
    public function unregisterModelInterceptor(string $model, string $interceptorClass, null|array|string|BackedEnum $key = null): void
    {
        $conditions = $this->normalizeKeyConditions($key);
        $entries = $this->modelInterceptors[$model] ?? [];
        $this->modelInterceptors[$model] = array_values(array_filter($entries, fn (array $entry): bool => $entry['class'] !== $interceptorClass || ! $this->conditionsEqual($entry['conditions'], $conditions)));
    }

    /**
     * @param  class-string<object>  $oldInterceptorClass
     * @param  class-string<object>  $newInterceptorClass
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     */
    public function replaceModelInterceptor(string $model, string $oldInterceptorClass, string $newInterceptorClass, null|array|string|BackedEnum $key = null, int $priority = 0): void
    {
        $this->unregisterModelInterceptor($model, $oldInterceptorClass, $key);
        $this->registerModelInterceptor($model, $newInterceptorClass, $key, $priority);
    }

    /**
     * Template method to create a model entity applying interceptors before and after persistence.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  callable  $persist  function(object|array $data): object
     * @param  string  $interceptorInterface  The required interceptor interface (for type check)
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     * @return TModel
     */
    public function createModel(string $model, array|string|BackedEnum $key, callable $persist, string $interceptorInterface): object
    {
        $modelData = [];

        $interceptorClasses = $this->getInterceptorsForModelAndKey($model, $key);

        foreach ($interceptorClasses as $interceptorClass) {
            $interceptor = $this->resolveModelInterceptor($interceptorClass, $interceptorInterface);
            $modelData = $this->callModelInterceptorBeforeCreate($interceptor, $modelData);
        }

        $entity = $persist($modelData);

        if (! $entity instanceof $model) {
            throw new InvalidArgumentException(sprintf('Model interceptor persist callback must return %s.', $model));
        }

        foreach ($interceptorClasses as $interceptorClass) {
            $interceptor = $this->resolveModelInterceptor($interceptorClass, $interceptorInterface);
            $this->callModelInterceptorAfterCreated($interceptor, $entity, $modelData);
        }

        return $entity;
    }

    /**
     * Create or update a model entity by conditions, applying interceptors before and after persistence.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     * @param  callable  $persist  function(array $data): array (should return merged data)
     * @return TModel
     */
    public function createOrUpdateModel(string $model, array|string|BackedEnum $key, callable $persist, string $interceptorInterface): object
    {
        $modelData = [];
        $interceptorClasses = $this->getInterceptorsForModelAndKey($model, $key);

        foreach ($interceptorClasses as $interceptorClass) {
            $interceptor = $this->resolveModelInterceptor($interceptorClass, $interceptorInterface);
            $modelData = $this->callModelInterceptorBeforeCreateOrUpdate($interceptor, $modelData);
        }

        $conditions = is_array($key) ? $key : ['key' => $key instanceof BackedEnum ? $key->value : $key];
        $existing = $model::query()->where($conditions)->first();
        $mergedData = $persist($modelData);
        if ($existing !== null) {
            $existing->fill($mergedData);
            $existing->save();
            $entity = $existing;
        } else {
            $entity = $model::query()->create($mergedData);
        }

        foreach ($interceptorClasses as $interceptorClass) {
            $interceptor = $this->resolveModelInterceptor($interceptorClass, $interceptorInterface);
            $this->callModelInterceptorAfterCreatedOrUpdated($interceptor, $entity, $mergedData);
        }

        if (! $entity instanceof $model) {
            throw new InvalidArgumentException(sprintf('Model interceptor persist callback must return %s.', $model));
        }

        return $entity;
    }

    /**
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     * @return array<int, class-string<object>> Ordered by priority (ascending).
     *
     * @internal
     */
    public function getInterceptorsForModelAndKey(string $model, null|array|string|BackedEnum $key): array
    {
        $lookup = $this->normalizeKeyConditions($key);
        $entries = $this->modelInterceptors[$model] ?? [];

        $matches = array_filter($entries, fn (array $entry): bool => $this->conditionsMatch($entry['conditions'], $lookup));

        // Already sorted by priority via sortInterceptors; ensure order.
        usort($matches, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_map(static fn (array $entry): string => $entry['class'], $matches);
    }

    /**
     * Recursively merge defaults and data for model creation, so nested arrays are merged and scalars in $data take precedence.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @internal
     */
    public function mergeModelInterceptorData(array $defaults, array $data): array
    {
        $merged = $defaults;
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $merged) && is_array($merged[$key]) && is_array($value)) {
                $merged[$key] = $this->mergeModelInterceptorData($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  class-string<object>  $interceptorClass
     */
    protected function resolveModelInterceptor(string $interceptorClass, string $interceptorInterface): object
    {
        /** @var Container $container */
        $container = App::getFacadeRoot();
        $instance = $container->make($interceptorClass);
        // Use explicit guard for clearer static analysis.
        if (! ($instance instanceof $interceptorInterface)) {
            throw new InvalidArgumentException(sprintf('Interceptor %s must implement %s.', $interceptorClass, $interceptorInterface));
        }

        return $instance;
    }

    /**
     * @param  array<string, mixed>  $modelData
     * @return array<string, mixed>
     */
    private function callModelInterceptorBeforeCreate(object $interceptor, array $modelData): array
    {
        return $this->callModelInterceptorArrayMethod($interceptor, 'beforeCreate', $modelData);
    }

    /**
     * @param  array<string, mixed>  $modelData
     * @return array<string, mixed>
     */
    private function callModelInterceptorBeforeCreateOrUpdate(object $interceptor, array $modelData): array
    {
        return $this->callModelInterceptorArrayMethod(
            $interceptor,
            method_exists($interceptor, 'beforeCreateOrUpdate') ? 'beforeCreateOrUpdate' : 'beforeCreate',
            $modelData,
        );
    }

    /**
     * @param  array<string, mixed>  $modelData
     */
    private function callModelInterceptorAfterCreated(object $interceptor, object $entity, array $modelData): void
    {
        $this->callModelInterceptorVoidMethod($interceptor, 'afterCreated', $entity, $modelData);
    }

    /**
     * @param  array<string, mixed>  $modelData
     */
    private function callModelInterceptorAfterCreatedOrUpdated(object $interceptor, object $entity, array $modelData): void
    {
        $this->callModelInterceptorVoidMethod(
            $interceptor,
            method_exists($interceptor, 'afterCreatedOrUpdated') ? 'afterCreatedOrUpdated' : 'afterCreated',
            $entity,
            $modelData,
        );
    }

    /**
     * @param  array<string, mixed>  $modelData
     * @return array<string, mixed>
     */
    private function callModelInterceptorArrayMethod(object $interceptor, string $method, array $modelData): array
    {
        $callback = [$interceptor, $method];

        if (! is_callable($callback)) {
            throw new InvalidArgumentException(sprintf('Model interceptor method %s is not callable.', $method));
        }

        $result = $callback($modelData);

        if (! is_array($result)) {
            throw new InvalidArgumentException(sprintf('Model interceptor method %s must return an array.', $method));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $modelData
     */
    private function callModelInterceptorVoidMethod(object $interceptor, string $method, object $entity, array $modelData): void
    {
        $callback = [$interceptor, $method];

        if (! is_callable($callback)) {
            throw new InvalidArgumentException(sprintf('Model interceptor method %s is not callable.', $method));
        }

        $callback($entity, $modelData);
    }

    private function sortInterceptors(string $model): void
    {
        $entries = $this->modelInterceptors[$model] ?? [];
        usort($entries, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $this->modelInterceptors[$model] = $entries;
    }

    /**
     * Normalize key input to conditions array.
     *
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     * @return array<string, string|int|float|bool>
     */
    private function normalizeKeyConditions(null|array|string|BackedEnum $key): array
    {
        if ($key === null) {
            return [];
        }

        if (is_array($key)) {
            $normalized = [];
            foreach ($key as $column => $value) {
                $normalized[$column] = $value instanceof BackedEnum ? $value->value : $value;
            }

            return $normalized;
        }

        $value = $key instanceof BackedEnum ? $key->value : $key;

        // For backward compatibility, map string scalar to primary column named 'key'.
        return ['key' => $value];
    }

    /**
     * Check if entry conditions equal target conditions exactly.
     *
     * @param  array<string, string|int|float|bool>  $a
     * @param  array<string, string|int|float|bool>  $b
     */
    private function conditionsEqual(array $a, array $b): bool
    {
        if ($b === []) {
            return true;
        }

        if (count($a) !== count($b)) {
            return false;
        }

        return array_all($a, fn (string|int|float|bool $value, string $column): bool => array_key_exists($column, $b) && $b[$column] === $value);
    }

    /**
     * Check if all entry conditions are satisfied by provided lookup conditions.
     * Entry can specify a subset of conditions; all specified must match exactly.
     *
     * @param  array<string, string|int|float|bool>  $entryConditions
     * @param  array<string, string|int|float|bool>  $lookupConditions
     */
    private function conditionsMatch(array $entryConditions, array $lookupConditions): bool
    {
        return array_all($entryConditions, fn (string|int|float|bool $value, string $column): bool => array_key_exists($column, $lookupConditions) && $lookupConditions[$column] === $value);
    }
}
