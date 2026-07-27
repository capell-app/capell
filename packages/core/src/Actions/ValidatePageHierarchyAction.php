<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Page;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Detects nested-set integrity problems in the page tree that would be
 * masked by a publish/rebuild. Returns true when the tree is broken.
 *
 * @method static bool run()
 */
final class ValidatePageHierarchyAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        $table = (new Page)->getTable();
        $grammar = DB::connection()->getQueryGrammar();

        $oddness = DB::table($table)
            ->where(function ($query) use ($grammar): void {
                $query
                    ->whereColumn('_lft', '>=', '_rgt')
                    ->orWhereRaw(sprintf(
                        '(%s - %s) %% 2 = 0',
                        $grammar->wrap('_rgt'),
                        $grammar->wrap('_lft'),
                    ));
            })
            ->count();

        $duplicates = DB::table($table . ' as c1')
            ->crossJoin($table . ' as c2')
            ->whereColumn('c1.id', '<', 'c2.id')
            ->where(function ($query): void {
                $query
                    ->whereColumn('c1._lft', 'c2._lft')
                    ->orWhereColumn('c1._rgt', 'c2._rgt')
                    ->orWhereColumn('c1._lft', 'c2._rgt')
                    ->orWhereColumn('c1._rgt', 'c2._lft');
            })
            ->count();

        $wrongParent = DB::table($table . ' as c')
            ->crossJoin($table . ' as p')
            ->crossJoin($table . ' as i')
            ->whereColumn('c.parent_id', 'p.id')
            ->whereColumn('i.id', '<>', 'p.id')
            ->whereColumn('i.id', '<>', 'c.id')
            ->where(function ($query): void {
                $query
                    ->whereNotBetweenColumns('c._lft', ['p._lft', 'p._rgt'])
                    ->orWhere(function ($query): void {
                        $query
                            ->whereBetweenColumns('c._lft', ['i._lft', 'i._rgt'])
                            ->whereBetweenColumns('i._lft', ['p._lft', 'p._rgt']);
                    });
            })
            ->count();

        $missingParent = DB::table($table)
            ->whereNotNull('parent_id')
            ->whereNotExists(function ($query) use ($table): void {
                $query
                    ->select('id')
                    ->from($table . ' as p')
                    ->whereColumn($table . '.parent_id', 'p.id');
            })
            ->count();

        return ($oddness > 0) || ($duplicates > 0) || ($wrongParent > 0) || ($missingParent > 0);
    }
}
