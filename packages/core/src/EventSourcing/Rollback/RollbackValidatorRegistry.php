<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

use Capell\Core\EventSourcing\Rollback\Contracts\RollbackValidator;
use Illuminate\Database\Eloquent\Model;

use function resolve;

/**
 * Per-aggregate registry of rollback validators. Registered as a singleton in
 * the core service provider so any adopter can attach uniqueness / referential
 * integrity checks for its own model without modifying the rollback engine.
 */
final class RollbackValidatorRegistry
{
    /**
     * @var array<class-string<Model>, list<class-string<RollbackValidator>>>
     */
    private array $validators = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<RollbackValidator>  $validatorClass
     */
    public function register(string $modelClass, string $validatorClass): void
    {
        $this->validators[$modelClass][] = $validatorClass;
    }

    /**
     * @return list<RollbackValidator>
     */
    public function for(Model $model): array
    {
        return array_map(
            static fn (string $validatorClass): RollbackValidator => resolve($validatorClass),
            $this->validators[$model::class] ?? [],
        );
    }
}
