<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Database;

use Closure;
use Illuminate\Database\Query\Builder;

/** @mixin Builder */
final class BuilderMacros
{
    /** @return Closure(string, string, mixed): Builder */
    public function whereNullOr(): Closure
    {
        return fn (string $column, string $operator, mixed $value = null): Builder => $this->where(
            fn (Builder $query): Builder => $query->whereNull($column)->orWhere($column, $operator, $value),
        );
    }
}
