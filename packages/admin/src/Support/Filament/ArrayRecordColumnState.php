<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Filament;

use Closure;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Model;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Keeps table columns working when their records are arrays.
 *
 * A package may give every column a state callback that declares `Model $record`
 * (lara-zeus/spatie-translatable 2.1 does, to read translated attributes). Filament
 * tables built from arrays, such as the Extensions and Marketplace tables, then fail
 * with a TypeError on their first cell. Such a callback is wrapped so that array
 * records fall back to the column's own state lookup, while Eloquent records still
 * reach the original callback unchanged.
 */
final class ArrayRecordColumnState
{
    /**
     * Registered as an important configuration: Filament runs those after every
     * ordinary configuration and the column's own setUp(), so the guard sees the
     * callback other packages configured whatever order their providers boot in.
     */
    public static function register(): void
    {
        Column::configureUsing(self::guard(...), isImportant: true);
    }

    public static function guard(Column $column): void
    {
        $callback = $column->getGetStateUsingCallback();

        if (! $callback instanceof Closure || ! self::requiresModelRecord($callback)) {
            return;
        }

        $column->getStateUsing(static function (Column $column) use ($callback): mixed {
            if (! $column->getRecord() instanceof Model) {
                return $column->getStateFromRecord();
            }

            return $column->evaluate($callback);
        });
    }

    private static function requiresModelRecord(Closure $callback): bool
    {
        foreach (new ReflectionFunction($callback)->getParameters() as $parameter) {
            if ($parameter->getName() !== 'record') {
                continue;
            }

            $type = $parameter->getType();

            return $type instanceof ReflectionNamedType
                && ! $type->allowsNull()
                && is_a($type->getName(), Model::class, true);
        }

        return false;
    }
}
