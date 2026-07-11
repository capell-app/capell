<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static> publishedLatest()
 * @method static Builder<static> publishedOldest()
 * @method static Builder<static> ordered(string $direction = 'asc')
 * @method static Builder<static> alphabetical(Language $language, string $direction = 'asc')
 *
 * @template TModel of Model
 *
 * @mixin Model
 */
trait HasPageOrdering
{
    /**
     * @param  Builder<Model>  $query
     */
    protected function scopePublishedLatest(Builder $query): void
    {
        $query->orderByRaw(self::literalSql(sprintf(
            'COALESCE(%s, %s) DESC',
            $this->qualifyColumn('visible_from'),
            $this->qualifyColumn('created_at'),
        )))
            ->orderBy($this->qualifyColumn('id'), 'desc');
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function scopePublishedOldest(Builder $query): void
    {
        $query->orderByRaw(self::literalSql(sprintf(
            'COALESCE(%s, %s) ASC',
            $this->qualifyColumn('visible_from'),
            $this->qualifyColumn('created_at'),
        )))
            ->orderBy($this->qualifyColumn('id'), 'asc');
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function scopeOrdered(Builder $query, string $direction = 'asc'): void
    {
        // Ensure null 'order' values are sorted last, then order by the value in the
        // requested direction and finally by the nested-set left value as a tie-breaker.
        $orderColumn = $this->qualifyColumn('order');

        // Wrap the column name with the current connection grammar so reserved words
        // (like `order`) are quoted correctly for the database (SQLite, MySQL, etc.).
        $wrappedOrderColumn = $this->getConnection()->getQueryGrammar()->wrap($orderColumn);

        $sortDirection = self::sortDirection($direction);

        $query->orderByRaw(self::literalSql($wrappedOrderColumn . ' IS NULL ASC'))
            ->orderBy($orderColumn, $sortDirection)
            ->orderBy($this->getLftName(), $sortDirection);
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function scopeAlphabetical(Builder $query, Language $language, string $direction = 'asc'): void
    {
        $query->orderBy(
            Translation::query()->select('title')
                ->whereColumn('translatable_id', $this->qualifyColumn('id'))
                ->where('translatable_type', $this->getMorphClass())
                ->where('language_id', $language->id),
            self::sortDirection($direction),
        );
    }

    /**
     * @return 'asc'|'desc'
     */
    private static function sortDirection(string $direction): string
    {
        return mb_strtolower($direction) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @return literal-string
     */
    private static function literalSql(string $sql): string
    {
        /** @var literal-string $sql */
        return $sql;
    }
}
