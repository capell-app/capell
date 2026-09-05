<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;

/** Composes query-builder subqueries without accepting caller-supplied SQL. */
final readonly class PropertyValuePrecedenceExpression implements Expression
{
    public function __construct(
        private Builder $presence,
        private Builder $ownValue,
        private Builder $inheritedValue,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        return 'CASE WHEN EXISTS (' . $this->presence->toSql()
            . ') THEN (' . $this->ownValue->toSql()
            . ') ELSE (' . $this->inheritedValue->toSql() . ') END';
    }

    /** @return list<mixed> */
    public function bindings(): array
    {
        return [...$this->presence->getBindings(), ...$this->ownValue->getBindings(), ...$this->inheritedValue->getBindings()];
    }
}
