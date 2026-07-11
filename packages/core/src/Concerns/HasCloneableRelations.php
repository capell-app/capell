<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

trait HasCloneableRelations
{
    /**
     * Relations on this model that should be cloned
     *
     * @var array<string, list<string>>
     */
    protected array $cloneableRelations = [];

    /**
     * @return list<string>
     */
    public function getCloneableRelations(string $model): array
    {
        return $this->cloneableRelations[$model] ?? [];
    }

    public function addCloneableRelations(string $model, string $relation): void
    {
        if (! isset($this->cloneableRelations[$model])) {
            $this->cloneableRelations[$model] = [];
        }

        $this->cloneableRelations[$model][] = $relation;
    }
}
