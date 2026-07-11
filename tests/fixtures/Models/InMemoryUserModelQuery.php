<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Models;

class InMemoryUserModelQuery
{
    /** @var array<string, mixed> */
    private array $conditions = [];

    /** @param array<string, mixed> $conditions */
    public function where(array $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function first(): ?InMemoryUserModel
    {
        foreach (InMemoryUserModel::$records as $record) {
            if ($this->matchesConditions($record->attributes, $this->conditions)) {
                return $record;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): InMemoryUserModel
    {
        $entity = new InMemoryUserModel($attributes);
        InMemoryUserModel::$records[] = $entity;

        return $entity;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $conditions
     */
    private function matchesConditions(array $attributes, array $conditions): bool
    {
        return array_all($conditions, fn ($conditionValue, $conditionKey): bool => array_key_exists($conditionKey, $attributes) && $attributes[$conditionKey] === $conditionValue);
    }
}
