<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

use Capell\Core\Models\Media;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Counts the pages and terms whose property values point at a media item.
 * A page counts once however many languages or positions hold the image.
 */
final class PropertyMediaUsage
{
    /**
     * @param  Builder<PagePropertyValue>  $pageValues  values already narrowed to one media item
     * @param  Builder<TermPropertyValue>  $termValues  values already narrowed to one media item
     */
    public static function count(Builder $pageValues, Builder $termValues): int
    {
        return (clone $pageValues)->distinct()->count('page_id')
            + (clone $termValues)->distinct()->count('term_id');
    }

    /**
     * Correlated per-row subqueries for a media listing, one per value table.
     * Each selects the number of distinct owners referencing the outer row.
     *
     * @param  Builder<Media>  $mediaQuery
     * @param  Builder<PagePropertyValue>  $pageValues
     * @param  Builder<TermPropertyValue>  $termValues
     * @return array{0: QueryBuilder, 1: QueryBuilder}
     */
    public static function correlatedCounts(Builder $mediaQuery, Builder $pageValues, Builder $termValues): array
    {
        $mediaKey = $mediaQuery->qualifyColumn($mediaQuery->getModel()->getKeyName());

        return [
            (clone $pageValues)
                ->selectRaw('COUNT(DISTINCT page_id)')
                ->whereColumn($pageValues->qualifyColumn('media_id'), $mediaKey)
                ->toBase(),
            (clone $termValues)
                ->selectRaw('COUNT(DISTINCT term_id)')
                ->whereColumn($termValues->qualifyColumn('media_id'), $mediaKey)
                ->toBase(),
        ];
    }

    /**
     * Correlated existence subqueries for filtering a media listing by use.
     *
     * @param  Builder<Media>  $mediaQuery
     * @param  Builder<PagePropertyValue>  $pageValues
     * @param  Builder<TermPropertyValue>  $termValues
     * @return array{0: QueryBuilder, 1: QueryBuilder}
     */
    public static function correlatedExistence(Builder $mediaQuery, Builder $pageValues, Builder $termValues): array
    {
        $mediaKey = $mediaQuery->qualifyColumn($mediaQuery->getModel()->getKeyName());

        return [
            (clone $pageValues)->selectRaw('1')->whereColumn($pageValues->qualifyColumn('media_id'), $mediaKey)->toBase(),
            (clone $termValues)->selectRaw('1')->whereColumn($termValues->qualifyColumn('media_id'), $mediaKey)->toBase(),
        ];
    }
}
