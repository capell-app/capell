<?php

declare(strict_types=1);

namespace Capell\Core\Data\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

final readonly class SqlFragment
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}

    public static function raw(string $sql): self
    {
        return new self($sql);
    }

    public static function value(mixed $value): self
    {
        return new self('?', [$value]);
    }

    /** @return Expression<literal-string> */
    public function expression(): Expression
    {
        /** @var literal-string $sql */
        $sql = $this->sql;

        return new Expression($sql);
    }

    public function applyOrder(Builder $query): void
    {
        $query->orderBy($this->expression());
        $query->addBinding($this->bindings, 'order');
    }

    public function applyWhere(Builder $query, string $boolean = 'and'): void
    {
        $query->whereRaw($this->expression(), $this->bindings, $boolean);
    }

    public function applySelect(Builder $query): void
    {
        $query->addSelect($this->expression());
        $query->addBinding($this->bindings, 'select');
    }
}
