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

        $oddness = DB::table($table)
            ->selectRaw($this->literalSql('(select count(1) from `' . $table . '` where (`_lft` >= `_rgt` or (`_rgt` - `_lft`) % 2 = 0)) as oddness'))
            ->value('oddness');

        $duplicates = DB::table($table)
            ->selectRaw($this->literalSql('(select count(1) from `' . $table . '` as c1, `' . $table . '` as c2 where c1.`id` < c2.`id` and (c1.`_lft`=c2.`_lft` or c1.`_rgt`=c2.`_rgt` or c1.`_lft`=c2.`_rgt` or c1.`_rgt`=c2.`_lft`)) as duplicates'))
            ->value('duplicates');

        $wrongParent = DB::table($table)
            ->selectRaw($this->literalSql('(select count(1) from `' . $table . '` as c, `' . $table . '` as p, `' . $table . '` as i where c.`parent_id`=p.`id` and i.`id` <> p.`id` and i.`id` <> c.`id` and (c.`_lft` not between p.`_lft` and p.`_rgt` or c.`_lft` between i.`_lft` and i.`_rgt` and i.`_lft` between p.`_lft` and p.`_rgt`)) as wrong_parent'))
            ->value('wrong_parent');

        $missingParent = DB::table($table)
            ->selectRaw($this->literalSql('(select count(1) from `' . $table . '` where (`parent_id` is not null and not exists (select 1 from `' . $table . '` as p where `' . $table . '`.`parent_id` = p.`id` limit 1))) as missing_parent'))
            ->value('missing_parent');

        return ($oddness > 0) || ($duplicates > 0) || ($wrongParent > 0) || ($missingParent > 0);
    }

    /**
     * @return literal-string
     */
    private function literalSql(string $sql): string
    {
        /** @var literal-string $sql */
        return $sql;
    }
}
