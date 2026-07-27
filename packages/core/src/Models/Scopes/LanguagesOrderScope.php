<?php

declare(strict_types=1);

namespace Capell\Core\Models\Scopes;

use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class LanguagesOrderScope implements Scope
{
    /**
     * @param  list<mixed>  $languageIds
     */
    public function __construct(private readonly array $languageIds) {}

    /**
     * @param  list<mixed>  $languageIds
     */
    public static function applyTo(BuilderContract $builder, array $languageIds): BuilderContract
    {
        $languageIds = self::normalizeLanguageIds($languageIds);

        if ($languageIds === []) {
            return $builder;
        }

        return $builder->orderByRaw(
            self::literalSql('CASE language_id ' .
            implode(' ', array_map(
                fn (int $index): string => sprintf('WHEN ? THEN %d', $index),
                array_keys($languageIds),
            )) .
            sprintf(' ELSE %d END', count($languageIds))),
            $languageIds,
        );
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        self::applyTo($builder, $this->languageIds);
    }

    /**
     * @return literal-string
     */
    private static function literalSql(string $sql): string
    {
        /** @var literal-string $sql */
        return $sql;
    }

    /**
     * @param  list<mixed>  $languageIds
     * @return list<int>
     */
    private static function normalizeLanguageIds(array $languageIds): array
    {
        $languageIds = array_filter(
            $languageIds,
            static fn (mixed $languageId): bool => is_int($languageId)
                || (is_string($languageId) && ctype_digit($languageId)),
        );

        return array_values(array_map(static fn (int|string $languageId): int => (int) $languageId, $languageIds));
    }
}
